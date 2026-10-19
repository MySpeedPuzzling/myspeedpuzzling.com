<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Where am I way off the median?" - the histogram (free) and the outliers more than 30 % off your usual (members).
 * usualPercent = your median, in percent faster than the median solver.
 */
readonly final class InsightsHistogramSection
{
    public const int LIST_LIMIT = 4;

    /**
     * @param list<InsightsHistogramBin> $bins
     * @param list<InsightsResultItem> $slowOutliers slowest against your usual first
     * @param list<InsightsResultItem> $fastOutliers fastest against your usual first
     */
    public function __construct(
        public array $bins,
        public null|float $usualPercent,
        public int $slowerThanMedianCount,
        public int $sampleSize,
        public int $slowOutlierCount,
        public int $fastOutlierCount,
        public array $slowOutliers,
        public array $fastOutliers,
        public bool $notSureYet,
    ) {
    }
}
