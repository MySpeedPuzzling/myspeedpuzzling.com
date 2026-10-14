<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One page of MarkAbandonedOnlineMediaHandler: how many uploads were marked abandoned (nothing in their store long
 * after the URL ran out), how many objects are there although the phone never reported them (left as they are - the
 * report may still come), how many stores could not be asked (tried again by the next run) and how many rows were
 * skipped (a store that is not configured). `nextAfterCreatedAt` / `nextAfterId` = where the next page starts, null
 * when nothing is left; `storeFailing` = the page stopped early because a store kept failing.
 */
readonly final class MarkedAbandonedOnlineMedia
{
    public function __construct(
        public int $abandoned,
        public int $inStore,
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
