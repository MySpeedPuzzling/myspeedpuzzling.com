<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Am I getting better?" - monthly medians over the period (at least 4 months), the rolling usual, and how the first
 * month with a median compares with the last (change in ratio units: -0.05 = 5 points faster against the median solver).
 */
readonly final class InsightsFormSection
{
    /**
     * @param list<InsightsFormMonth> $months oldest first
     */
    public function __construct(
        public array $months,
        public null|float $firstMedianRatio,
        public null|float $lastMedianRatio,
        public null|float $change,
        public int $sampleSize,
        public bool $notSureYet,
    ) {
    }
}
