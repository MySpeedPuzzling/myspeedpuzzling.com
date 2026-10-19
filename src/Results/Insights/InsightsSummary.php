<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * The tiles above the sections. speedRatio = median of your time ÷ the puzzle median (0.9 = 10 % faster than the
 * median solver); trendChange = your median of the last 6 months ÷ 12-18 months ago − 1 (negative = faster now; all
 * filters but the period); consistencySpread = q75 ÷ q25 of your residuals (members).
 */
readonly final class InsightsSummary
{
    public function __construct(
        public int $resultCount,
        public int $ratioCount,
        public null|float $speedRatio,
        public null|float $trendChange,
        public int $trendRecentCount,
        public int $trendOlderCount,
        public null|float $consistencySpread,
        public int $consistencySampleSize,
    ) {
    }
}
