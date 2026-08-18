<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Services\UploadFailureCollector;

/**
 * Wraps the real S3 adapter so an object-storage outage never breaks a user
 * flow: failed writes land in the local UploadSpool (drained back to S3 by
 * the myspeedpuzzling:upload-spooled-files cron), failed deletes are queued,
 * and reads fall back to spooled payloads.
 *
 * Catches \Throwable, not FilesystemException: AsyncAwsS3Adapter::fileExists()
 * leaks raw AsyncAws NetworkException on timeouts.
 *
 * Known accepted degradation: PuzzleImageNamer's uniqueness check may reuse a
 * deterministic name during an outage (stale browser-cache risk only).
 */
final readonly class FailoverS3Adapter implements FilesystemAdapter
{
    public function __construct(
        private FilesystemAdapter $inner,
        private UploadSpool $spool,
        private UploadFailureCollector $collector,
        private LoggerInterface $logger,
    ) {
    }

    public function fileExists(string $path): bool
    {
        try {
            if ($this->inner->fileExists($path)) {
                return true;
            }
        } catch (\Throwable) {
            // S3 unreachable - a spooled pending write still counts as existing
        }

        return $this->spool->hasPayload($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        try {
            $this->inner->write($path, $contents, $config);
            $this->spool->resolve($path);
        } catch (\Throwable $exception) {
            $this->spoolFailedWrite($path, $contents, $exception);
        }
    }

    /**
     * @param resource $contents
     */
    public function writeStream(string $path, $contents, Config $config): void
    {
        $contents = $this->ensureSeekable($contents);
        $position = ftell($contents);

        try {
            $this->inner->writeStream($path, $contents, $config);
            // A stale spooled payload must never overwrite this fresh object later
            $this->spool->resolve($path);
        } catch (\Throwable $exception) {
            // The failed S3 attempt consumed (part of) the stream
            if ($position !== false) {
                fseek($contents, $position);
            }

            $this->spoolFailedWrite($path, $contents, $exception);
        }
    }

    public function read(string $path): string
    {
        try {
            return $this->inner->read($path);
        } catch (\Throwable $exception) {
            if ($this->spool->hasPayload($path)) {
                return $this->spool->readPayload($path);
            }

            throw $exception;
        }
    }

    public function readStream(string $path)
    {
        try {
            return $this->inner->readStream($path);
        } catch (\Throwable $exception) {
            if ($this->spool->hasPayload($path)) {
                return $this->spool->readPayloadStream($path);
            }

            throw $exception;
        }
    }

    public function delete(string $path): void
    {
        try {
            $this->inner->delete($path);
            // A spooled pending write must not resurrect the deleted file
            $this->spool->resolve($path);
        } catch (\Throwable $exception) {
            $this->spool->spoolDelete($path, $this->describeError($exception));

            $this->logger->error('Object storage delete failed - queued for automatic retry', [
                'exception' => $exception,
                'path' => $path,
            ]);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        try {
            return $this->inner->mimeType($path);
        } catch (\Throwable $exception) {
            if ($this->spool->hasPayload($path)) {
                return new FileAttributes($path, mimeType: $this->spool->payloadMimeType($path));
            }

            throw $exception;
        }
    }

    public function lastModified(string $path): FileAttributes
    {
        try {
            return $this->inner->lastModified($path);
        } catch (\Throwable $exception) {
            if ($this->spool->hasPayload($path)) {
                return new FileAttributes($path, lastModified: $this->spool->payloadLastModified($path));
            }

            throw $exception;
        }
    }

    public function fileSize(string $path): FileAttributes
    {
        try {
            return $this->inner->fileSize($path);
        } catch (\Throwable $exception) {
            if ($this->spool->hasPayload($path)) {
                return new FileAttributes($path, fileSize: $this->spool->payloadFileSize($path));
            }

            throw $exception;
        }
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        try {
            $this->inner->copy($source, $destination, $config);
        } catch (\Throwable $exception) {
            try {
                // Source may only exist in the spool; destination spools on failure
                $stream = $this->readStream($source);
                $this->writeStream($destination, $stream, $config);

                if (is_resource($stream)) {
                    fclose($stream);
                }
            } catch (\Throwable) {
                throw $exception;
            }
        }
    }

    /**
     * @param string|resource $contents
     */
    private function spoolFailedWrite(string $path, mixed $contents, \Throwable $exception): void
    {
        try {
            $this->spool->spoolWrite($path, $contents, $this->describeError($exception));
        } catch (\Throwable $spoolException) {
            $this->logger->error('Object storage write failed and spooling failed too - upload is lost', [
                'exception' => $exception,
                'spool_exception' => $spoolException,
                'path' => $path,
            ]);

            throw $exception;
        }

        $this->collector->recordFailure($path);

        $this->logger->error('Object storage write failed - file spooled for automatic retry', [
            'exception' => $exception,
            'path' => $path,
        ]);
    }

    /**
     * @param resource $stream
     * @return resource
     */
    private function ensureSeekable($stream)
    {
        $metadata = stream_get_meta_data($stream);

        if ($metadata['seekable']) {
            return $stream;
        }

        $buffer = fopen('php://temp', 'r+b');
        assert(is_resource($buffer));
        stream_copy_to_stream($stream, $buffer);
        rewind($buffer);

        return $buffer;
    }

    private function describeError(\Throwable $exception): string
    {
        return $exception::class . ': ' . $exception->getMessage();
    }
}
