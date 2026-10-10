<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class BadgeCatalogGroup
{
    /**
     * @param list<BadgeCatalogEntry> $tiers
     */
    public function __construct(
        public BadgeType $type,
        public array $tiers,
        public null|BadgeProgress $progressToNext,
    ) {
    }

    public function earnedCount(): int
    {
        return count(array_filter($this->tiers, static fn (BadgeCatalogEntry $entry): bool => $entry->earned));
    }

    public function hasAnyEarned(): bool
    {
        foreach ($this->tiers as $entry) {
            if ($entry->earned) {
                return true;
            }
        }

        return false;
    }

    /**
     * The badge that stands for the whole achievement (the catalog card, the explainer): the highest tier earned.
     */
    public function highestEarnedTier(): null|BadgeTier
    {
        $highest = null;

        foreach ($this->tiers as $entry) {
            if ($entry->earned && $entry->tier !== null && ($highest === null || $entry->tier->value > $highest->value)) {
                $highest = $entry->tier;
            }
        }

        return $highest;
    }
}
