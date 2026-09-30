<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * The prediction stored on a solving time: either a prediction, or "could not be predicted -
 * the data was not there at that moment". Never an error - a failure leaves the time unevaluated.
 */
readonly final class SolvingTimePrediction
{
    private function __construct(
        public null|TimePredictionResult $result,
        public TimePredictionSource $source,
        public int $modelVersion = TimePredictionCalculator::MODEL_VERSION,
    ) {
    }

    public static function predicted(
        TimePredictionResult $result,
        TimePredictionSource $source,
        int $modelVersion = TimePredictionCalculator::MODEL_VERSION,
    ): self {
        return new self($result, $source, $modelVersion);
    }

    public static function notPredictable(
        TimePredictionSource $source,
        int $modelVersion = TimePredictionCalculator::MODEL_VERSION,
    ): self {
        return new self(null, $source, $modelVersion);
    }

    public function isPredictable(): bool
    {
        return $this->result !== null;
    }
}
