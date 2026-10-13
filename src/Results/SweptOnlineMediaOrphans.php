<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One page of SweepOnlineMediaOrphansHandler: how many objects were listed, how many of them were orphans old enough
 * to go, how many were deleted and how many the store failed to delete (tried again on a later sweep). `hasMore`: the
 * listing goes on - the next page (now, or in the next run) starts where this one ended.
 */
readonly final class SweptOnlineMediaOrphans
{
    public function __construct(
        public int $listed,
        public int $orphans,
        public int $deleted,
        public int $failed,
        public bool $hasMore,
    ) {
    }
}
