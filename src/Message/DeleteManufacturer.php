<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\MergeDecisionSource;

/**
 * Deletes a brand nothing uses any more - e.g. an empty copy left behind by a typo
 * (docs/features/brand-duplicates.md). Refused while anything still points at it.
 */
readonly final class DeleteManufacturer
{
    public function __construct(
        public string $manufacturerId,
        public string $reviewerId,
        public MergeDecisionSource $decisionSource,
        public null|string $decisionNote = null,
    ) {
    }
}
