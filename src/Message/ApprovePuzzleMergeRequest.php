<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Locked on the survivor only - the lock takes one key. The other puzzles are deleted by the merge: an edit of one of
 * them committed in the same moment is lost with it, which needs a moderator saving a puzzle reported as a duplicate
 * while another approves the merge; the review form's record versions refuse every earlier save.
 */
readonly final class ApprovePuzzleMergeRequest implements SerializedByLock
{
    public function __construct(
        public string $mergeRequestId,
        public string $reviewerId,
        public string $survivorPuzzleId,
        public string $mergedName,
        public null|string $mergedEan,
        public null|string $mergedIdentificationNumber,
        public int $mergedPiecesCount,
        public null|string $mergedManufacturerId,
        public null|string $selectedImagePuzzleId,
        // Where the decision came from, and - for reviews done in bulk - how sure it
        // was and why. Kept on the audit record so a merge can be re-examined later.
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        public null|MergeDecisionConfidence $decisionConfidence = null,
        public null|string $decisionNote = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->survivorPuzzleId);
    }
}
