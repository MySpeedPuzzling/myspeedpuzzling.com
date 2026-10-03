<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;

/**
 * Folds duplicate brands into the survivor (docs/features/brand-duplicates.md).
 * Any brand into any brand, approved or not - unlike the approval queue, which
 * only folds a new brand into an approved one.
 */
readonly final class MergeManufacturers
{
    /**
     * @param list<string> $mergedManufacturerIds
     */
    public function __construct(
        public string $survivorManufacturerId,
        public array $mergedManufacturerIds,
        public string $reviewerId,
        public MergeDecisionSource $decisionSource,
        // The survivor's name as the brand itself writes it - null keeps it
        public null|string $survivorName = null,
        public null|MergeDecisionConfidence $decisionConfidence = null,
        public null|string $decisionNote = null,
    ) {
    }
}
