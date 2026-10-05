<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Locked per puzzle like every change of its record - the puzzle id is the change request's puzzle (the handler checks).
 */
readonly final class ApprovePuzzleChangeRequest implements SerializedByLock
{
    /**
     * @param list<string> $selectedFields Proposed fields applied as proposed, the rest of the puzzle stays as it
     *                                     is (internal API): name, nameLanguage, alternativeNames, manufacturer,
     *                                     piecesCount, ean, identificationNumber, image. Not used when $reviewed is given.
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
        // The admin review: the other names the review was loaded with - the reviewer's list is applied as a diff
        // against them, so a name changed by somebody else meanwhile stays. Null = $reviewed is the whole list.
        public null|PuzzleNames $reviewedFrom = null,
        // The internal API: the other names and the main title's language to apply instead of the proposed ones (a
        // selected field only) - the list as it should end up, applied as a diff like the proposal. false = as proposed
        public null|PuzzleNames $alternativeNamesOverride = null,
        public null|false|string $nameLanguageOverride = false,
        // The internal API: the puzzle's record as the caller read it (PuzzleRecordVersion) - a puzzle changed since
        // refuses the approval; null checks nothing. The admin review sends it in $reviewed
        public null|string $recordVersion = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
