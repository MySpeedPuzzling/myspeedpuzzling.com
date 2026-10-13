<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One batch of PruneOnlineMediaHandler: how many periodic photos and how many other evidence objects (finish photos,
 * bursts, start / end / empty mat photos) were deleted - from object storage and their rows - and whether more are
 * waiting (the command asks again).
 */
readonly final class PrunedOnlineMedia
{
    public function __construct(
        public int $periodic,
        public int $evidence,
        public bool $hasMore,
    ) {
    }
}
