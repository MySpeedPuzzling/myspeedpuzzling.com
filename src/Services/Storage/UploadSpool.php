<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\UnableToReadFile;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Persistent on-disk queue of storage operations that could not reach S3.
 *
 * Layout under the spool root: payload/<storage-key> holds the file bytes,
 * meta/<storage-key>.json holds the operation bookkeeping. Meta files are not
 * written atomically; pendingOperations() reconciles instead - a corrupt or
 * missing meta is rebuilt from its payload, so a crash between the two writes
 * never loses a file.
 */
final class UploadSpool
{
    private const string PAYLOAD_PREFIX = 'payload/';
    private const string META_PREFIX = 'meta/';
    private const string META_SUFFIX = '.json';
    private const int ERROR_MESSAGE_MAX_LENGTH = 500;

    private readonly Filesystem $spool;

    public function __construct(
        FilesystemAdapter $spoolAdapter,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        $this->spool = new Filesystem($spoolAdapter);
    }

    /**
     * @param string|resource $contents
     *
     * @throws FilesystemException
     */
    public function spoolWrite(string $key, mixed $contents, string $error): void
    {
        if (is_string($contents)) {
            $this->spool->write(self::PAYLOAD_PREFIX . $key, $contents);
        } else {
            $this->spool->writeStream(self::PAYLOAD_PREFIX . $key, $contents);
        }

        $this->recordFailedAttempt($key, SpooledOperationType::Write, $error);
    }

    /**
     * @throws FilesystemException
     */
    public function spoolDelete(string $key, string $error): void
    {
        // A pending write for the same key is superseded by the delete
        $this->spool->delete(self::PAYLOAD_PREFIX . $key);

        $this->recordFailedAttempt($key, SpooledOperationType::Delete, $error);
    }

    /**
     * The key no longer needs reconciliation - the operation reached S3
     * (or was superseded). Idempotent.
     */
    public function resolve(string $key): void
    {
        $this->spool->delete(self::PAYLOAD_PREFIX . $key);
        $this->spool->delete(self::META_PREFIX . $key . self::META_SUFFIX);
    }

    /**
     * Current meta for the key, or null when nothing is pending (or the meta
     * is unreadable). Used by the cron to re-check freshness right before
     * executing an operation from an earlier directory listing.
     */
    public function pendingOperationFor(string $key): null|SpooledOperation
    {
        return $this->readMeta($key);
    }

    public function hasPayload(string $key): bool
    {
        return $this->spool->fileExists(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * @throws FilesystemException
     */
    public function readPayload(string $key): string
    {
        return $this->spool->read(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * @return resource
     *
     * @throws FilesystemException
     */
    public function readPayloadStream(string $key)
    {
        return $this->spool->readStream(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * @throws FilesystemException
     */
    public function payloadLastModified(string $key): int
    {
        return $this->spool->lastModified(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * @throws FilesystemException
     */
    public function payloadFileSize(string $key): int
    {
        return $this->spool->fileSize(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * @throws FilesystemException
     */
    public function payloadMimeType(string $key): string
    {
        return $this->spool->mimeType(self::PAYLOAD_PREFIX . $key);
    }

    /**
     * Detects a payload being re-spooled while the cron uploads it.
     *
     * @return array{size: int, lastModified: int}|null
     */
    public function payloadFingerprint(string $key): null|array
    {
        try {
            return [
                'size' => $this->spool->fileSize(self::PAYLOAD_PREFIX . $key),
                'lastModified' => $this->spool->lastModified(self::PAYLOAD_PREFIX . $key),
            ];
        } catch (FilesystemException) {
            return null;
        }
    }

    public function markAttemptFailed(SpooledOperation $operation, string $error): void
    {
        $this->recordFailedAttempt($operation->key, $operation->op, $error);
    }

    /**
     * Everything waiting for reconciliation, oldest failure first. Also heals
     * crash leftovers: corrupt meta or orphan payload becomes a pending write.
     *
     * @return list<SpooledOperation>
     */
    public function pendingOperations(): array
    {
        $operations = [];
        $keysWithMeta = [];

        foreach ($this->spool->listContents(rtrim(self::META_PREFIX, '/'), deep: true) as $item) {
            $path = $item->path();

            if (!$item->isFile() || !str_ends_with($path, self::META_SUFFIX)) {
                continue;
            }

            $key = substr($path, strlen(self::META_PREFIX), -strlen(self::META_SUFFIX));
            $operation = $this->readMeta($key);

            if ($operation === null) {
                if ($this->hasPayload($key)) {
                    $this->logger->warning('Corrupt upload spool meta - rebuilding from payload', ['key' => $key]);
                    $operation = $this->recordFailedAttempt($key, SpooledOperationType::Write, 'Meta rebuilt from payload');
                } else {
                    $this->logger->warning('Corrupt upload spool meta without payload - dropping', ['key' => $key]);
                    $this->spool->delete($path);

                    continue;
                }
            }

            $keysWithMeta[$key] = true;
            $operations[] = $operation;
        }

        foreach ($this->spool->listContents(rtrim(self::PAYLOAD_PREFIX, '/'), deep: true) as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $key = substr($item->path(), strlen(self::PAYLOAD_PREFIX));

            if (isset($keysWithMeta[$key])) {
                continue;
            }

            $this->logger->warning('Upload spool payload without meta - rebuilding meta', ['key' => $key]);
            $operations[] = $this->recordFailedAttempt($key, SpooledOperationType::Write, 'Meta rebuilt from payload');
        }

        usort(
            $operations,
            static fn (SpooledOperation $a, SpooledOperation $b): int => $a->firstFailedAt <=> $b->firstFailedAt,
        );

        return $operations;
    }

    private function recordFailedAttempt(string $key, SpooledOperationType $op, string $error): SpooledOperation
    {
        $existing = $this->readMeta($key);
        $now = $this->clock->now();

        $operation = new SpooledOperation(
            key: $key,
            op: $op,
            firstFailedAt: $existing->firstFailedAt ?? $now,
            lastAttemptAt: $now,
            attempts: ($existing->attempts ?? 0) + 1,
            lastError: mb_substr($error, 0, self::ERROR_MESSAGE_MAX_LENGTH),
        );

        $this->spool->write(
            self::META_PREFIX . $key . self::META_SUFFIX,
            json_encode([
                'key' => $operation->key,
                'op' => $operation->op->value,
                'firstFailedAt' => $operation->firstFailedAt->format(\DateTimeInterface::ATOM),
                'lastAttemptAt' => $operation->lastAttemptAt->format(\DateTimeInterface::ATOM),
                'attempts' => $operation->attempts,
                'lastError' => $operation->lastError,
            ], JSON_THROW_ON_ERROR),
        );

        return $operation;
    }

    private function readMeta(string $key): null|SpooledOperation
    {
        try {
            $json = $this->spool->read(self::META_PREFIX . $key . self::META_SUFFIX);
        } catch (UnableToReadFile) {
            return null;
        }

        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (
            !is_array($data)
            || !is_string($data['key'] ?? null)
            || !is_string($data['op'] ?? null)
            || !is_string($data['firstFailedAt'] ?? null)
            || !is_string($data['lastAttemptAt'] ?? null)
            || !is_int($data['attempts'] ?? null)
            || !is_string($data['lastError'] ?? null)
            || $data['key'] !== $key
        ) {
            return null;
        }

        $op = SpooledOperationType::tryFrom($data['op']);
        $firstFailedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['firstFailedAt']);
        $lastAttemptAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $data['lastAttemptAt']);

        if ($op === null || $firstFailedAt === false || $lastAttemptAt === false) {
            return null;
        }

        return new SpooledOperation(
            key: $data['key'],
            op: $op,
            firstFailedAt: $firstFailedAt,
            lastAttemptAt: $lastAttemptAt,
            attempts: $data['attempts'],
            lastError: $data['lastError'],
        );
    }
}
