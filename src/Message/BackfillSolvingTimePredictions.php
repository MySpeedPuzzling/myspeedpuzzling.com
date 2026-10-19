<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Reconstructs the prediction of every pending solo time of one player and the side-by-side row of the
 * current model (solving_time_prediction) of every solo time that has none, plus the listed times even
 * when they already carry one (an edit invalidated it, and a backfill that was already running may
 * have written a prediction for the time's old data in the meantime). Handled synchronously by the
 * backfill command (one message per player) and asynchronously after a back-dated add or an edit
 * (SolvingTimePredictor).
 *
 * Deliberately no RequiresFreshEntityManagerState: the add/edit handlers dispatch it, and that
 * middleware would clear the entity manager in the middle of their web request. The command clears
 * after every player itself, the messenger worker after every message.
 */
readonly final class BackfillSolvingTimePredictions
{
    public function __construct(
        public string $playerId,
        /** @var list<string> */
        public array $reevaluateTimeIds = [],
        // Also replace every reconstructed prediction - shown, and the current model's side-by-side row - with one of
        // the current fit (live ones are history, never); only once the model is fitted
        public bool $recomputeReconstructed = false,
        // Also replace every reconstructed image / blend row older than the out-of-fold estimate its puzzle has now (a
        // newer derive) - the one-off before an evaluation of the estimates; only once the model is fitted
        public bool $recomputeImage = false,
    ) {
    }
}
