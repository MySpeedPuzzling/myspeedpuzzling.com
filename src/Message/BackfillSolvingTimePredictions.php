<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Reconstructs the prediction of every pending solo time of one player, plus the listed times even
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
    ) {
    }
}
