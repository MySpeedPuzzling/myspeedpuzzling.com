<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * A copy removed automatically, as its tracker sees it under "Removed automatically" - read from the stored
 * snapshot, the row itself is gone.
 */
readonly final class AutoRemovedResult
{
    public function __construct(
        public string $removalId,
        public string $puzzleId,
        public string $puzzleName,
        public null|int $secondsToSolve,
        public DateTimeImmutable $solvedAt,
        public DateTimeImmutable $savedAt,
        public DateTimeImmutable $removedAt,
        public string $keptTimeId,
    ) {
    }
}
