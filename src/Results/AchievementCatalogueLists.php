<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AchievementCatalogueFilter;

/**
 * Everything an achievements page shows, built in PHP from the catalogue it already loaded
 * (AchievementCatalogueRows): the rows per filter, the closest next steps and the summary numbers.
 */
readonly final class AchievementCatalogueLists
{
    /**
     * @param array<string, list<AchievementCatalogueRow>> $lists filter value → rows in that filter's order
     * @param list<AchievementCatalogueRow> $closest the closest next steps: at most 3, highest progress first
     */
    public function __construct(
        public array $lists,
        public array $closest,
        /** The unearned row nearest to its Bronze - the "Your first one is close" empty state */
        public null|AchievementCatalogueRow $nearestFirst,
        public int $earnedTypes,
        public int $totalTypes,
        public int $earnedTiers,
        public int $totalTiers,
    ) {
    }

    /**
     * @return list<AchievementCatalogueRow>
     */
    public function rows(AchievementCatalogueFilter $filter): array
    {
        return $this->lists[$filter->value] ?? [];
    }

    public function count(AchievementCatalogueFilter $filter): int
    {
        return count($this->rows($filter));
    }
}
