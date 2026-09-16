<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;

readonly final class ApprovePuzzleMergeRequest
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
}
