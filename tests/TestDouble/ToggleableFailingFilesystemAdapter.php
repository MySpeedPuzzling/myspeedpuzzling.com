<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InMemory\InMemoryFilesystemAdapter;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

/**
 * Stands in for the raw S3 adapter (app.storage.s3_adapter) in tests. Default
 * is a plain in-memory pass-through; setFailing(true) simulates an object
 * storage outage. fileExists() then throws a raw \RuntimeException on purpose:
 * the real AsyncAwsS3Adapter leaks unwrapped AsyncAws NetworkException there.
 */
final class ToggleableFailingFilesystemAdapter implements FilesystemAdapter
{
    private InMemoryFilesystemAdapter $inner;

    private bool $failing = false;

    public function __construct()
    {
        $this->inner = new InMemoryFilesystemAdapter();
    }

    public function setFailing(bool $failing): void
    {
        $this->failing = $failing;
    }

    public function fileExists(string $path): bool
    {
        if ($this->failing) {
            throw new \RuntimeException('Simulated object storage network timeout');
        }

        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        if ($this->failing) {
            throw UnableToWriteFile::atLocation($path, 'Simulated object storage outage');
        }

        $this->inner->write($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        if ($this->failing) {
            // The real S3 attempt consumes (part of) the stream before failing
            fread($contents, 3);

            throw UnableToWriteFile::atLocation($path, 'Simulated object storage outage');
        }

        $this->inner->writeStream($path, $contents, $config);
    }

    public function read(string $path): string
    {
        if ($this->failing) {
            throw UnableToReadFile::fromLocation($path, 'Simulated object storage outage');
        }

        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        if ($this->failing) {
            throw UnableToReadFile::fromLocation($path, 'Simulated object storage outage');
        }

        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        if ($this->failing) {
            throw UnableToDeleteFile::atLocation($path, 'Simulated object storage outage');
        }

        $this->inner->delete($path);
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
        if ($this->failing) {
            throw UnableToRetrieveMetadata::mimeType($path, 'Simulated object storage outage');
        }

        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        if ($this->failing) {
            throw UnableToRetrieveMetadata::lastModified($path, 'Simulated object storage outage');
        }

        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        if ($this->failing) {
            throw UnableToRetrieveMetadata::fileSize($path, 'Simulated object storage outage');
        }

        return $this->inner->fileSize($path);
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
        if ($this->failing) {
            throw UnableToCopyFile::fromLocationTo($source, $destination);
        }

        $this->inner->copy($source, $destination, $config);
    }
}
