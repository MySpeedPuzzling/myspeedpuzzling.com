<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\CompetitionPageSection;

/**
 * What the section forms need to know about the page they edit.
 */
readonly final class PageSectionOwnerOverview
{
    public function __construct(
        public string $name,
        public bool $isOnline,
        // Visible and hidden - the page's own, never the series sections an edition shows
        public int $sectionsCount = 0,
    ) {
    }

    public function canAddSection(): bool
    {
        return $this->sectionsCount < CompetitionPageSection::MAX_PER_PAGE;
    }
}
