<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * One bar of the median histogram: results between fromPercent and toPercent faster than the puzzle's median solver
 * (negative = slower). The end bars hold everything beyond ±60 %.
 */
readonly final class InsightsHistogramBin
{
    public function __construct(
        public int $fromPercent,
        public int $toPercent,
        public int $count,
    ) {
    }
}
