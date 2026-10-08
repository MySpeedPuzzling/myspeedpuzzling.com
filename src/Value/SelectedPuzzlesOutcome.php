<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a move, copy or remove of selected collection puzzles did - the controllers turn it into the toast.
 */
readonly final class SelectedPuzzlesOutcome
{
    public function __construct(
        // Moved, copied or removed
        public int $changed,
        // Already in the target collection: a move only took them out of the source, a copy skipped them
        public int $alreadyThere = 0,
        // No longer in the source collection (another tab moved them meanwhile), or not available (a secret puzzle)
        public int $skipped = 0,
    ) {
    }
}
