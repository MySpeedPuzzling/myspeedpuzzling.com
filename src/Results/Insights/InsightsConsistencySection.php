<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "How consistent am I?" (members): spread = q75 ÷ q25 of your residuals (the middle half of your results around your
 * usual: plusMinus = (spread − 1) ÷ 2, "±6 %"), the last 6 months against before, and the profile card's consistency
 * percentile against all puzzlers.
 */
readonly final class InsightsConsistencySection
{
    public const int MIN_RESULTS = 10;

    public function __construct(
        public null|float $spread,
        public null|float $plusMinus,
        public int $sampleSize,
        public null|float $recentSpread,
        public int $recentSampleSize,
        public null|float $earlierSpread,
        public int $earlierSampleSize,
        public null|float $percentile,
        public bool $notSureYet,
    ) {
    }
}
