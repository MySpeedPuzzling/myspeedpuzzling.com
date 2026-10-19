<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Insights;

/**
 * "How good are my predictions?" (members): the hit rate of your likely windows - only predictions of the calibrated
 * model (model version ≥ CALIBRATED_MODEL_VERSION), whose stored range is the 50 % window; older ranges promised
 * nothing calibrated and are only counted (`olderPredictionCount`). medianMargin = median actual ÷ predicted − 1.
 */
readonly final class InsightsAccuracySection
{
    public const int CALIBRATED_MODEL_VERSION = 3;

    public const int MIN_PREDICTIONS = 10;

    /** The share of solves the likely window promises */
    public const float TARGET_HIT_RATE = 0.5;

    public function __construct(
        public int $sampleSize,
        public int $hits,
        public null|float $hitRate,
        public int $fasterThanWindow,
        public int $slowerThanWindow,
        public null|float $medianMargin,
        public int $firstTrySampleSize,
        public int $firstTryHits,
        public int $repeatSampleSize,
        public int $repeatHits,
        public int $olderPredictionCount,
        public bool $notSureYet,
    ) {
    }
}
