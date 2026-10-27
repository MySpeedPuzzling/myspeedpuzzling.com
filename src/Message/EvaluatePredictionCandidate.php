<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PredictorKind;

/**
 * Measures a candidate time model's stored side-by-side predictions against a reference (gate v2) and stores its gate
 * record - `myspeedpuzzling:evaluate-prediction-candidate`.
 */
readonly final class EvaluatePredictionCandidate
{
    public function __construct(
        public PredictorKind $candidate,
        public int $candidateVersion,
        public PredictorKind $reference,
        public int $referenceVersion,
        // Solves of the last N months
        public int $solvedSinceMonths = 12,
        // Only puzzles added in the last N months (null = every puzzle)
        public null|int $newerThanMonths = null,
    ) {
    }
}
