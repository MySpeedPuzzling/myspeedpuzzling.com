<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The achievements catalogue's segmented filter (?show=). "Next up" and "Earned" read the viewer's own achievements,
 * so they exist for members only; everybody can browse "All" and "Rarest".
 */
enum AchievementCatalogueFilter: string
{
    case NextUp = 'next';
    case Earned = 'earned';
    case All = 'all';
    case Rarest = 'rarest';

    public function isPersonal(): bool
    {
        return $this === self::NextUp || $this === self::Earned;
    }

    public function translationKey(): string
    {
        // "Rarest first": the label says it is an order, not a subset (design-spec §8.6)
        return $this === self::Rarest ? 'badges.shelf.filter_rarest' : 'badges.catalogue.filter.' . $this->value;
    }
}
