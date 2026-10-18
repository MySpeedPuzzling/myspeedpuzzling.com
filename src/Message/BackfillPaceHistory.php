<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Brings player_pace_history in line with the solves - every player (keyset batches) or one. Safe to
 * run any time: pairs whose rows are current are left alone (docs/features/puzzle-intelligence/README.md
 * "Pace history"). Handled synchronously by `myspeedpuzzling:backfill-pace-history`.
 */
readonly final class BackfillPaceHistory
{
    public function __construct(
        public null|string $playerId = null,
    ) {
    }
}
