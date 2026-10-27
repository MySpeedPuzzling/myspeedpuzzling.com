<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;

/**
 * What the predictors said about one solve (solving_time_prediction, Insights plan §5.4): the statistical model's
 * prediction (personal for a repeat - the one shown, SolvingTimePrediction::forDisplay()) and, for a first try on a
 * puzzle with a picture estimate, the `image` and `blend` predictors' - null when the puzzle had no usable estimate
 * (none of its current picture, a secret puzzle, or for a reconstruction none out of fold). Repeats have no image
 * predictions: a personal prediction does not use the puzzle's difficulty.
 */
readonly final class PredictionsSideBySide
{
    private function __construct(
        public SolvingTimePrediction $statistical,
        public bool $firstTry,
        public null|ImagePriorInput $imageEstimate = null,
        public null|SolvingTimePrediction $image = null,
        public null|SolvingTimePrediction $blend = null,
        // The candidate model's (v4, never shown) prediction - null when the candidate was never fitted (no row then)
        public null|SolvingTimePrediction $candidate = null,
    ) {
    }

    public static function repeat(TimePredictionResult $personal, TimePredictionSource $source, null|TimePredictionResult $candidate = null): self
    {
        return new self(
            SolvingTimePrediction::predicted($personal, $source),
            firstTry: false,
            candidate: $candidate !== null ? SolvingTimePrediction::predicted($candidate, $source, CandidatePredictionCalculator::MODEL_VERSION) : null,
        );
    }

    /**
     * The same predictions with the candidate's: $fitted = the candidate has a fit (a first try it cannot predict then
     * gets a "not predictable" row of its model version).
     */
    public function withCandidate(bool $fitted, null|TimePredictionResult $candidate, TimePredictionSource $source): self
    {
        if ($fitted === false) {
            return $this;
        }

        return new self(
            $this->statistical,
            $this->firstTry,
            $this->imageEstimate,
            $this->image,
            $this->blend,
            $candidate !== null
                ? SolvingTimePrediction::predicted($candidate, $source, CandidatePredictionCalculator::MODEL_VERSION)
                : SolvingTimePrediction::notPredictable($source, CandidatePredictionCalculator::MODEL_VERSION),
        );
    }

    /**
     * A first try: the statistical prediction (null = no pace or no difficulty), and the image / blend ones when the
     * puzzle had an estimate.
     */
    public static function firstTry(
        TimePredictionSource $source,
        null|TimePredictionResult $statistical,
        null|ImagePriorInput $imageEstimate = null,
        null|TimePredictionResult $image = null,
        null|TimePredictionResult $blend = null,
    ): self {
        return new self(
            self::prediction($statistical, $source),
            true,
            $imageEstimate,
            $imageEstimate !== null ? self::prediction($image, $source) : null,
            $imageEstimate !== null ? self::prediction($blend, $source) : null,
        );
    }

    private static function prediction(null|TimePredictionResult $result, TimePredictionSource $source): SolvingTimePrediction
    {
        return $result !== null
            ? SolvingTimePrediction::predicted($result, $source)
            : SolvingTimePrediction::notPredictable($source);
    }
}
