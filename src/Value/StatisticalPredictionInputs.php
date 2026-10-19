<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;

/**
 * Everything a statistical (first-try) prediction of one player on one puzzle rests on, as the live tables hold it
 * now (GetPlayerPrediction, GetPlayerPredictions) or as it was right before a past solve (PredictionReconstructor) -
 * TimePredictionCalculator::statistical() turns it into the prediction, the same way for all of them.
 */
readonly final class StatisticalPredictionInputs
{
    public function __construct(
        public int $piecesCount,
        // The player's baseline at the piece count: direct (5+ qualifying first attempts) or interpolated/extrapolated
        public null|int $ownBaselineSeconds,
        public bool $ownBaselineDirect,
        // Qualifying first attempts behind a direct own baseline (0 otherwise)
        public int $ownQualifyingCount,
        // Drift and spread (log) of the pair's pace-history row before the prediction; null = none (0 and the global spread)
        public null|float $ownDrift,
        public null|float $ownSpread,
        // The player's direct 500-piece pace, for the thin-size rule
        public null|int $pace500Seconds,
        public null|float $drift500,
        public null|float $spread500,
        // The puzzle: index count k, sum of the log indices, the median score when rated (k >= 5)
        public int $kIndices,
        public null|float $sumLogIndices,
        public null|float $difficultyScore,
        public PriorContext $prior,
    ) {
    }
}
