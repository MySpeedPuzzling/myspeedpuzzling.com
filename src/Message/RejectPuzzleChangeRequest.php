<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Locked per puzzle like its approval - an approval and a rejection of one request (or two rejections) never both pass
 * the "still pending" check. The puzzle id is the change request's puzzle (the handler checks).
 */
readonly final class RejectPuzzleChangeRequest implements SerializedByLock
{
    public function __construct(
        public string $changeRequestId,
        // The change request's puzzle - the lock key
        public string $puzzleId,
        public string $reviewerId,
        // Shown to the player who proposed the change - the decision's `note`
        public string $rejectionReason,
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        // An internal note for the decision log, never shown to the player (the internal API) - the decision's
        // details.decisionNote, `note` being the rejection reason
        public null|string $decisionNote = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
