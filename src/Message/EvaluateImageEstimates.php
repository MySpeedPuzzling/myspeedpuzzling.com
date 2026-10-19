<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Measures the picture-based estimates on the stored side-by-side predictions and stores the gate record
 * (ImageEstimateEvaluation, Insights plan §5.4) - `myspeedpuzzling:evaluate-image-estimates`.
 */
readonly final class EvaluateImageEstimates
{
    public function __construct(
        // Newer puzzles = added in the last N months
        public int $newerThanMonths = 12,
    ) {
    }
}
