<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One tag key the app knows (PuzzleTaxonomy): its facet, the group a page shows it in and the translation key of its
 * label (messages domain, all 6 locales). Several keys may share a label (every photo style reads "Photo").
 */
readonly final class PuzzleTagDefinition
{
    public function __construct(
        public string $key,
        public PuzzleTagFacet $facet,
        public PuzzleTagGroup $group,
        public string $labelKey,
    ) {
    }
}
