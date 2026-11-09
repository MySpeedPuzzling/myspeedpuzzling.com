<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToListContents;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;

/**
 * Object storage that refuses every call and remembers each one - proves a code path never waits on object storage
 * (docs/features/photo-uploads/README.md: starting an upload makes no object-storage call).
 */
final class ExplodingFilesystemAdapter implements FilesystemAdapter
{
    /** @var list<string> */
    public array $calls = [];

    public function fileExists(string $path): bool
    {
        $this->calls[] = 'fileExists ' . $path;

        throw UnableToCheckExistence::forLocation($path);
    }

    public function directoryExists(string $path): bool
    {
        $this->calls[] = 'directoryExists ' . $path;

        throw UnableToCheckExistence::forLocation($path);
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->calls[] = 'write ' . $path;

        throw UnableToWriteFile::atLocation($path, 'Exploding storage');
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->calls[] = 'writeStream ' . $path;

        throw UnableToWriteFile::atLocation($path, 'Exploding storage');
    }

    public function read(string $path): string
    {
        $this->calls[] = 'read ' . $path;

        throw UnableToReadFile::fromLocation($path, 'Exploding storage');
    }

    public function readStream(string $path)
    {
        $this->calls[] = 'readStream ' . $path;

        throw UnableToReadFile::fromLocation($path, 'Exploding storage');
    }

    public function delete(string $path): void
    {
        $this->calls[] = 'delete ' . $path;

        throw UnableToDeleteFile::atLocation($path, 'Exploding storage');
    }

    public function deleteDirectory(string $path): void
    {
        $this->calls[] = 'deleteDirectory ' . $path;

        throw UnableToDeleteDirectory::atLocation($path, 'Exploding storage');
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->calls[] = 'createDirectory ' . $path;

        throw UnableToCreateDirectory::atLocation($path, 'Exploding storage');
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->calls[] = 'setVisibility ' . $path;

        throw UnableToSetVisibility::atLocation($path, 'Exploding storage');
    }

    public function visibility(string $path): FileAttributes
    {
        $this->calls[] = 'visibility ' . $path;

        throw UnableToRetrieveMetadata::visibility($path, 'Exploding storage');
    }

    public function mimeType(string $path): FileAttributes
    {
        $this->calls[] = 'mimeType ' . $path;

        throw UnableToRetrieveMetadata::mimeType($path, 'Exploding storage');
    }

    public function lastModified(string $path): FileAttributes
    {
        $this->calls[] = 'lastModified ' . $path;

        throw UnableToRetrieveMetadata::lastModified($path, 'Exploding storage');
    }

    public function fileSize(string $path): FileAttributes
    {
        $this->calls[] = 'fileSize ' . $path;

        throw UnableToRetrieveMetadata::fileSize($path, 'Exploding storage');
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $this->calls[] = 'listContents ' . $path;

        throw UnableToListContents::atLocation($path, $deep, new \RuntimeException('Exploding storage'));
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->calls[] = 'move ' . $source;

        throw UnableToMoveFile::fromLocationTo($source, $destination);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->calls[] = 'copy ' . $source;

        throw UnableToCopyFile::fromLocationTo($source, $destination);
    }
}
