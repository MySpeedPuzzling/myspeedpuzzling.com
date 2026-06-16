<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One subject's result on one puzzle, with ranking and delta computed relative
 * to the other subjects in the comparison.
 */
readonly final class ComparisonEntry
{
    public function __construct(
        public string $subjectKey,
        public int $fastestTime,
        public DateTimeImmutable $fastestDate,
        public string $fastestTimeId,
        public null|int $firstTryTime,
        public null|DateTimeImmutable $firstTryDate,
        public int $rank,
        public bool $isFastest,
        public null|int $delta,
        public bool $isShared,
    ) {
    }
}
