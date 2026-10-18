<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * "Restore this picture" on a puzzle's history (docs/features/puzzle-approvals.md "Restoring a picture"): puts back the
 * picture an image change of the history replaced - a moderator's direct edit, locked per puzzle like every change of
 * its record.
 */
readonly final class RestorePuzzleImage implements SerializedByLock
{
    public function __construct(
        public string $puzzleId,
        // The decision log's row whose replaced picture comes back (PuzzleHistoryEntry::$decisionId)
        public string $decisionId,
        public string $moderatorId,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
