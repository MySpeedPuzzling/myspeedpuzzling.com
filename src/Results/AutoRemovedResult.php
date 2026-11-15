<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;

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
        // How exactly the solve day is known (date-precision.md): a day, or only its month or year
        public DatePrecision $datePrecision = DatePrecision::Day,
    ) {
    }
}
