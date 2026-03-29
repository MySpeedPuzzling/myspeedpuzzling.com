<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class TimePredictionResult
{
    public function __construct(
        public int $predictedSeconds,
        public int $rangeLowSeconds,
        public int $rangeHighSeconds,
        public float $difficultyForPlayer,
    ) {
    }
}
