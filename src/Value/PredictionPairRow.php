<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One solve with a stored candidate prediction and, of the same time, the reference prediction it is gated against
 * (GetPredictionPairRows) - whatever predictor and model version each is (solving_time_prediction).
 */
readonly final class PredictionPairRow
{
    public function __construct(
        public PiecesGroup $sizeGroup,
        // The puzzle's difficulty index count when predicted; null = a repeat (the player's own times)
        public null|int $kIndices,
        public int $actualSeconds,
        // When the puzzle was solved (finished_at, else tracked_at - epoch seconds): the out-of-period split
        public float $solvedAt,
        // The candidate row was written live (when the time was added) - else reconstructed by the backfill
        public bool $live,
        public StoredPredictionWindows $candidate,
        // Null when the reference had nothing for the time
        public null|StoredPredictionWindows $reference,
    ) {
    }
}
