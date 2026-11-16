<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Undo an approved puzzle merge from its audit (admins only): the merged puzzles exist again under their own ids and
 * everything the merge moved onto the survivor goes back (RevertPuzzleMergeRequestHandler).
 */
readonly final class RevertPuzzleMergeRequest implements SerializedByLock
{
    public function __construct(
        public string $mergeRequestId,
        public string $adminId,
        // The puzzle the merge kept - the request's survivor, checked by the handler; the lock is the survivor's record
        public string $survivorPuzzleId,
        public null|string $note = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->survivorPuzzleId);
    }
}
