<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

use DateTimeImmutable;

/**
 * The pace at the end of the year if the last 12 months' trend goes on (pace history).
 */
readonly final class PaceMilestone
{
    public function __construct(
        public int $piecesCount,
        public int $paceSeconds,
        public int $paceYearAgoSeconds,
        /** exp(12 × monthly log slope) − 1: −0.06 = 6 % faster a year */
        public float $changePerYear,
        public DateTimeImmutable $yearEnd,
        public int $projectedSeconds,
    ) {
    }
}
