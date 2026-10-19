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

    /**
     * What is shown for the solve (the columns on puzzle_solving_time): a first try of a puzzle without any difficulty
     * index rests on a prior alone - stored side by side (solving_time_prediction), not shown until the Insights
     * estimates are (Insights plan Phase 6).
     */
    public function forDisplay(): self
    {
        $result = $this->result;

        if ($result !== null && $result->isPersonalized === false && $result->kIndices === 0) {
            return self::notPredictable($this->source, $this->modelVersion);
        }

        return $this;
    }

    public function isPredictable(): bool
    {
        return $this->result !== null;
    }
}
