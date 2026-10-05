<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * A moderator's direct edit of a puzzle - no change request in between, recorded in the decision log.
 */
readonly final class EditPuzzle implements SerializedByLock
{
    public function __construct(
        public string $puzzleId,
        public string $editorId,
        public PuzzleRecordValues $values,
        // Why - kept in the puzzle's history
        public null|string $note = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
