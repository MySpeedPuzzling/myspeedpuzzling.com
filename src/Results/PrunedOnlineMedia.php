<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One page of PruneOnlineMediaHandler: how many periodic photos and how many other evidence objects (finish photos,
 * bursts, start / end / empty mat photos) were deleted - from object storage and their rows -, how many deletes
 * failed (row kept, tried again by the next run) and how many rows were skipped (a store that is not configured).
 * `nextAfterCreatedAt` / `nextAfterId` = where the next page starts, null when nothing is left; `storeFailing` = the
 * page stopped early because the store kept failing - the run stops, the next one tries again.
 */
readonly final class PrunedOnlineMedia
{
    public function __construct(
        public int $periodic,
        public int $evidence,
        public int $failed,
        public int $skipped,
        public null|DateTimeImmutable $nextAfterCreatedAt,
        public null|string $nextAfterId,
        public bool $storeFailing,
    ) {
    }

    public function hasMore(): bool
    {
        return $this->storeFailing === false && $this->nextAfterCreatedAt !== null && $this->nextAfterId !== null;
    }
}
