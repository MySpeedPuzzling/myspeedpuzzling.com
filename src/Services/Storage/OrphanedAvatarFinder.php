<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Storage;

use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemException;
use League\Flysystem\StorageAttributes;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetStoredFileReferences;

/**
 * Avatar objects in storage that no row points at: avatars of players deleted
 * before DeletePlayerStoredFiles existed, and avatars replaced on the edit-profile
 * page (EditProfileHandler never deletes the previous one).
 *
 * Objects younger than the grace period are skipped: EditProfileHandler uploads
 * the new avatar before its transaction commits, so a fresh object may be
 * referenced a moment later.
 */
readonly final class OrphanedAvatarFinder
{
    public const string PREFIX = 'avatars/';
    public const int GRACE_PERIOD_HOURS = 24;

    public function __construct(
        private Filesystem $filesystem,
        private GetStoredFileReferences $getStoredFileReferences,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return list<string>
     *
     * @throws FilesystemException
     */
    public function find(): array
    {
        $cutoff = $this->clock->now()->modify(sprintf('-%d hours', self::GRACE_PERIOD_HOURS))->getTimestamp();
        $paths = [];

        /** @var StorageAttributes $item */
        foreach ($this->filesystem->listContents(rtrim(self::PREFIX, '/'), true) as $item) {
            if ($item->isFile() === false) {
                continue;
            }

            $lastModified = $item->lastModified();

            // Unknown age counts as fresh - never delete what might be in flight
            if ($lastModified === null || $lastModified > $cutoff) {
                continue;
            }

            $paths[] = $item->path();
        }

        $referenced = $this->getStoredFileReferences->referencedAmong($paths);

        $orphans = array_values(array_filter(
            $paths,
            static fn (string $path): bool => isset($referenced[$path]) === false,
        ));
        sort($orphans);

        return $orphans;
    }

    public function isOrphan(string $path): bool
    {
        if (str_starts_with($path, self::PREFIX) === false) {
            return false;
        }

        return $this->getStoredFileReferences->referencedAmong([$path]) === [];
    }
}
