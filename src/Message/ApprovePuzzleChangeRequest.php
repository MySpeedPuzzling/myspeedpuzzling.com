<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Locked per puzzle like every change of its record - the puzzle id is the change request's puzzle (the handler checks).
 */
readonly final class ApprovePuzzleChangeRequest implements SerializedByLock
{
    /**
     * @param list<string> $selectedFields Proposed fields applied as proposed, the rest of the puzzle stays as it
     *                                     is (internal API). Not used when $reviewed is given.
     */
    public function __construct(
        public string $changeRequestId,
        // The change request's puzzle - the lock key
        public string $puzzleId,
        public string $reviewerId,
        public array $selectedFields = [],
        // The admin review: the whole puzzle as the reviewer wants it, proposed fields or not
        public null|PuzzleRecordValues $reviewed = null,
        public MergeDecisionSource $decisionSource = MergeDecisionSource::AdminUi,
        public null|string $decisionNote = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
