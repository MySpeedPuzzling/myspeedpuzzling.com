<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The pair/team prediction model's daily job (never shown - docs/features/puzzle-intelligence/README.md "Pair and team
 * predictions"): refits it and, while PREDICTION_GROUP_ROWS is on or $writeRows asks for it, writes the pair/team rows of
 * solving_time_prediction - Group\GroupModelRecalculator::run(). Handled synchronously by
 * `myspeedpuzzling:fit-group-prediction-model` (its own process).
 */
readonly final class FitGroupPredictionModel
{
    public function __construct(
        // Write the rows even while PREDICTION_GROUP_ROWS is off (`--rows`)
        public bool $writeRows = false,
        // Make every reconstructed row again with the new fit (`--recompute`)
        public bool $recompute = false,
    ) {
    }
}
