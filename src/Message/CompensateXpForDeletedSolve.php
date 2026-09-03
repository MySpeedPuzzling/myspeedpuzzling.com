<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Dispatched after a solve row was deleted. Carries the puzzle id because it is no longer
 * recoverable from the database once the row is gone — the XP ledger references solves via
 * plain uuid columns precisely so this compensation flow can still read the solve's entries.
 */
readonly final class CompensateXpForDeletedSolve
{
    public function __construct(
        public string $solvingTimeId,
        public string $puzzleId,
    ) {
    }
}
