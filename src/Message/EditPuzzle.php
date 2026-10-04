<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PuzzleRecordValues;

/**
 * A moderator's direct edit of a puzzle - no change request in between, recorded in the decision log.
 */
readonly final class EditPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $editorId,
        public PuzzleRecordValues $values,
        // Why - kept in the puzzle's history
        public null|string $note = null,
    ) {
    }
}
