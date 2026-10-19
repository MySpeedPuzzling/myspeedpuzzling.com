<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * One month of the form chart. medianRatio / q25 / q75 of your time ÷ the puzzle median, from MIN_RESULTS results on;
 * usual = the median of the month's results' own "your usual" (InsightsRow::form, the residuals' baseline); best =
 * the month's fastest result against its median.
 */
readonly final class InsightsFormMonth
{
    public const int MIN_RESULTS = 3;

    public function __construct(
        public string $month,
        public int $count,
        public null|float $medianRatio,
        public null|float $q25Ratio,
        public null|float $q75Ratio,
        public null|float $usual,
        public null|string $bestTimeId,
        public null|string $bestPuzzleId,
        public null|float $bestRatio,
    ) {
    }
}
