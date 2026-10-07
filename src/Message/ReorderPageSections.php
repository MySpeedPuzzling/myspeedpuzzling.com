<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * New order of one page's own content sections (exactly one of competitionId/seriesId). Every id must be a section of
 * that page - one of any other page refuses the whole request. Sections left out keep their order after the given ones.
 */
readonly final class ReorderPageSections
{
    /**
     * @param list<string> $sectionIds in the new page order
     */
    public function __construct(
        public null|string $competitionId,
        public null|string $seriesId,
        public array $sectionIds,
    ) {
    }
}
