<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;

/**
 * Locked on the survivor - the lock takes one key. The handler then locks the row of every puzzle of the merge (the
 * survivor and the ones it deletes, SELECT … FOR UPDATE) before it compares the record versions: an edit or an EAN link
 * of a merged puzzle committed before is seen by that check (the merge is refused), one committing later waits until
 * the merge committed.
 */
readonly final class ApprovePuzzleMergeRequest implements SerializedByLock
{
    /**
     * @param array<string, string> $recordVersions Puzzle id => the PuzzleRecordVersion the review was loaded with,
     *                                              for every reported puzzle - empty checks nothing (the internal API)
     */
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
        // The reviewer's names (the merge review's names editor): with mergedAlternativeNames, mergedNameLanguage is
        // taken as it is (null = English or not known). Without them the merge unions every name of the puzzles
        // (PuzzleMergeNames) and mergedNameLanguage, when given, only overrides the language it finds for mergedName
        public null|string $mergedNameLanguage = null,
        public null|PuzzleNames $mergedAlternativeNames = null,
        public array $recordVersions = [],
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->survivorPuzzleId);
    }
}
