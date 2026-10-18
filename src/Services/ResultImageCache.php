<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Result share images, kept on local disk for KEEP_HOURS - never in object storage. A card is opened right after the
 * result is added (the recap page, the share dialog, the link previewer of wherever it is shared) and almost never
 * again; whoever opens it later gets it redrawn. In the bucket they were kept for good: 490k cards, 420 GB, 62 % of it
 * (bucket scan 2026-09-22), while the cards of the last 48 hours take ~1.3 GB.
 *
 * Production keeps the directory on a volume shared by every container (lily.srv compose.yaml), the hourly
 * `myspeedpuzzling:prune-result-images` removes old cards. Losing the directory costs a redraw, so a failure here
 * never fails the image: it is logged at warning and the card is drawn anyway.
 */
readonly final class ResultImageCache
{
    public const int KEEP_HOURS = 48;

    public function __construct(
        private Filesystem $filesystem,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function read(string $path): null|string
    {
        try {
            if (
                $this->filesystem->fileExists($path) === false
                || $this->filesystem->lastModified($path) < $this->oldestKept()
            ) {
                return null;
            }

            return $this->filesystem->read($path);
        } catch (FilesystemException $exception) {
            $this->logger->warning('Could not read a kept result image', [
                'path' => $path,
                'exception' => $exception,
            ]);

            return null;
        }
    }

    public function write(string $path, string $content): void
    {
        // Written aside and renamed into place, so a request reading the card at the same moment never gets half of it
        $temporaryPath = sprintf('%s.%s.tmp', $path, bin2hex(random_bytes(4)));

        try {
            $this->filesystem->write($temporaryPath, $content);
            $this->filesystem->move($temporaryPath, $path);
        } catch (FilesystemException $exception) {
            $this->logger->warning('Could not keep a result image', [
                'path' => $path,
                'exception' => $exception,
            ]);
        }
    }

    /**
     * Deletes the cards older than KEEP_HOURS (and leftovers of an interrupted write), then the directories of
     * players left without any card. A card written into such a directory in between is lost - it is redrawn.
     */
    public function prune(): int
    {
        $oldestKept = $this->oldestKept();
        $deleted = 0;
        /** @var array<string, bool> $directoryKeepsSomething */
        $directoryKeepsSomething = [];

        try {
            /** @var StorageAttributes $item */
            foreach ($this->filesystem->listContents('players', true) as $item) {
                if ($item->isFile() === false) {
                    continue;
                }

                $playerDirectory = implode('/', array_slice(explode('/', $item->path()), 0, 2));
                $directoryKeepsSomething[$playerDirectory] ??= false;

                if (($item->lastModified() ?? 0) >= $oldestKept) {
                    $directoryKeepsSomething[$playerDirectory] = true;

                    continue;
                }

                $this->filesystem->delete($item->path());
                $deleted++;
            }

            foreach ($directoryKeepsSomething as $directory => $keepsSomething) {
                if ($keepsSomething === false) {
                    $this->filesystem->deleteDirectory($directory);
                }
            }
        } catch (FilesystemException $exception) {
            $this->logger->warning('Could not prune kept result images', [
                'exception' => $exception,
            ]);
        }

        return $deleted;
    }

    public function deleteOfPlayer(string $playerId): void
    {
        try {
            $this->filesystem->deleteDirectory("players/$playerId");
        } catch (FilesystemException $exception) {
            $this->logger->warning('Could not delete the kept result images of a deleted player', [
                'player_id' => $playerId,
                'exception' => $exception,
            ]);
        }
    }

    private function oldestKept(): int
    {
        return $this->clock->now()->getTimestamp() - self::KEEP_HOURS * 3600;
    }
}
