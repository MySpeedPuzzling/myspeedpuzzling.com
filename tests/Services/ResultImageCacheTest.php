<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToReadFile;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ResultImageCache;
use SpeedPuzzling\Web\Tests\TestDouble\InMemoryLogger;
use Symfony\Component\Clock\MockClock;

final class ResultImageCacheTest extends TestCase
{
    private const string PLAYER = '018d0000-0000-0000-0000-000000000001';
    private const string OTHER_PLAYER = '018d0000-0000-0000-0000-000000000002';

    private string $directory;
    private MockClock $clock;
    private InMemoryLogger $logger;
    private ResultImageCache $cache;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/result-image-cache-test-' . bin2hex(random_bytes(4));
        $this->clock = new MockClock('2026-10-18 12:00:00');
        $this->logger = new InMemoryLogger();
        $this->cache = new ResultImageCache(
            new Filesystem(new LocalFilesystemAdapter($this->directory)),
            $this->clock,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem(new LocalFilesystemAdapter($this->directory)))->deleteDirectory('');
        @rmdir($this->directory);
    }

    public function testKeptImageIsReadUntilItIsTooOld(): void
    {
        $path = $this->path(self::PLAYER, 'time-1');
        $this->cache->write($path, 'png');

        $this->age($path, hours: 47);
        self::assertSame('png', $this->cache->read($path));

        $this->age($path, hours: 49);
        self::assertNull($this->cache->read($path), 'An older card is drawn again');
    }

    public function testMissingImageIsNull(): void
    {
        self::assertNull($this->cache->read($this->path(self::PLAYER, 'nothing')));
    }

    public function testWriteLeavesNoTemporaryFileBehind(): void
    {
        $this->cache->write($this->path(self::PLAYER, 'time-1'), 'png');

        self::assertSame(['time-1.png'], array_values(array_diff(
            (array) scandir($this->directory . '/players/' . self::PLAYER . '/results'),
            ['.', '..'],
        )));
    }

    public function testPruneDeletesOldImagesAndEmptiedPlayerDirectories(): void
    {
        $old = $this->path(self::PLAYER, 'old');
        $fresh = $this->path(self::PLAYER, 'fresh');
        $otherOld = $this->path(self::OTHER_PLAYER, 'old');

        foreach ([$old, $fresh, $otherOld] as $path) {
            $this->cache->write($path, 'png');
        }

        $this->age($old, hours: 49);
        $this->age($fresh, hours: 1);
        $this->age($otherOld, hours: 72);

        self::assertSame(2, $this->cache->prune());

        self::assertFileDoesNotExist($this->directory . '/' . $old);
        self::assertFileExists($this->directory . '/' . $fresh);
        self::assertDirectoryDoesNotExist($this->directory . '/players/' . self::OTHER_PLAYER);
    }

    public function testPruneOfAnEmptyDirectoryDeletesNothing(): void
    {
        self::assertSame(0, $this->cache->prune());
    }

    public function testDeleteOfPlayerRemovesOnlyTheirImages(): void
    {
        $theirs = $this->path(self::PLAYER, 'time-1');
        $others = $this->path(self::OTHER_PLAYER, 'time-2');
        $this->cache->write($theirs, 'png');
        $this->cache->write($others, 'png');

        $this->cache->deleteOfPlayer(self::PLAYER);

        self::assertDirectoryDoesNotExist($this->directory . '/players/' . self::PLAYER);
        self::assertFileExists($this->directory . '/' . $others);
    }

    public function testStorageFailureIsLoggedAndNeverThrown(): void
    {
        $cache = new ResultImageCache(new Filesystem($this->brokenDisk()), $this->clock, $this->logger);
        $path = $this->path(self::PLAYER, 'time-1');

        $cache->write($path, 'png');
        self::assertNull($cache->read($path));
        $cache->deleteOfPlayer(self::PLAYER);
        self::assertSame(0, $cache->prune());

        self::assertTrue($this->logger->hasRecord('warning', 'Could not keep a result image'));
        self::assertTrue($this->logger->hasRecord('warning', 'Could not read a kept result image'));
        self::assertTrue($this->logger->hasRecord('warning', 'Could not delete the kept result images of a deleted player'));
        self::assertTrue($this->logger->hasRecord('warning', 'Could not prune kept result images'));
    }

    private function path(string $playerId, string $timeId): string
    {
        return "players/$playerId/results/$timeId.png";
    }

    private function age(string $path, int $hours): void
    {
        touch($this->directory . '/' . $path, $this->clock->now()->getTimestamp() - $hours * 3600);
        clearstatcache();
    }

    private function brokenDisk(): FilesystemAdapter
    {
        return new class () implements FilesystemAdapter {
            public function fileExists(string $path): bool
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function directoryExists(string $path): bool
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function write(string $path, string $contents, Config $config): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function writeStream(string $path, $contents, Config $config): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function read(string $path): string
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function readStream(string $path)
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function delete(string $path): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function deleteDirectory(string $path): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function createDirectory(string $path, Config $config): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function setVisibility(string $path, string $visibility): void
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function visibility(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function mimeType(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function lastModified(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function fileSize(string $path): FileAttributes
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function listContents(string $path, bool $deep): iterable
            {
                throw UnableToReadFile::fromLocation($path, 'broken disk');
            }

            public function move(string $source, string $destination, Config $config): void
            {
                throw UnableToReadFile::fromLocation($source, 'broken disk');
            }

            public function copy(string $source, string $destination, Config $config): void
            {
                throw UnableToReadFile::fromLocation($source, 'broken disk');
            }
        };
    }
}
