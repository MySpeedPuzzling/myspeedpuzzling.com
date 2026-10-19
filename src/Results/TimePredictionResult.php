<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\PaceSource;

/**
 * A time prediction. The range is the calibrated 50 % "likely" window (half of solves land inside it), the 80 %
 * range the wider one (README "Step 8: Time Prediction", windows) - both asymmetric around the centre.
 */
readonly final class TimePredictionResult
{
    public function __construct(
        public int $predictedSeconds,
        public int $rangeLowSeconds,
        public int $rangeHighSeconds,
        public float $difficultyForPlayer,
        public bool $isPersonalized = false,
        public null|int $personalSolveCount = null,
        public null|int $predictedAttemptNumber = null,
        public null|int $lastTimeSeconds = null,
        public null|int $range80LowSeconds = null,
        public null|int $range80HighSeconds = null,
        // Statistical predictions only: what the puzzle difficulty rested on, its index count k, the prior (log)
        // and the log difficulty used
        public null|DifficultySource $difficultySource = null,
        public null|int $kIndices = null,
        public null|float $priorLog = null,
        public null|float $logDifficulty = null,
        // The calibration bucket the window came from (WindowBucket::key())
        public null|string $windowBucket = null,
        // Statistical predictions only: what the pace rested on
        public null|PaceSource $paceSource = null,
    ) {
    }
}
