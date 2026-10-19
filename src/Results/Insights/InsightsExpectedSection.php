<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "Which puzzles went faster or slower than expected?" (members). Expected = the prediction stored with the result;
 * a prediction older than the calibrated model (model version < CALIBRATED_MODEL_VERSION) is multiplied by your usual
 * ratio to it per method (`calibration`, median actual ÷ predicted, 1.0 below 5 results) - "your margin" built in.
 * `firstTryMargin` = 1 − the statistical calibration: how much you beat first-try predictions by.
 */
readonly final class InsightsExpectedSection
{
    public const int LIST_LIMIT = 5;

    /** Deviations beyond ±10 % are "better / worse than expected" */
    public const float THRESHOLD = 0.1;

    /**
     * @param list<InsightsExpectedPoint> $points
     * @param array<string, float> $calibration TimePredictionMethod value => factor (older predictions only)
     * @param list<InsightsResultItem> $better most better than expected first
     * @param list<InsightsResultItem> $worse most worse than expected first
     */
    public function __construct(
        public array $points,
        public int $betterCount,
        public int $worseCount,
        public int $withinCount,
        public int $sampleSize,
        public int $calibratedCount,
        public array $calibration,
        public null|float $firstTryMargin,
        public array $better,
        public array $worse,
        public bool $notSureYet,
    ) {
    }
}
