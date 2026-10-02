<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The page tabs of the player header (templates/components/PlayerHeader.html.twig), in their order.
 * See docs/features/player-header.md.
 */
enum PlayerHeaderTab: string
{
    case Profile = 'profile';
    case Statistics = 'statistics';
    case Calendar = 'calendar';
    case Library = 'library';
    case Favorites = 'favorites';

    public function routeName(): string
    {
        return match ($this) {
            self::Profile => 'player_profile',
            self::Statistics => 'player_statistics',
            self::Calendar => 'activity_calendar',
            self::Library => 'puzzle_library',
            self::Favorites => 'player_favorite_puzzlers',
        };
    }

    public function translationKey(): string
    {
        return 'player_header.tabs.' . $this->value;
    }

    /**
     * Whom a player has in favorites (and who has them) is personal: only the owner gets the page and the tab
     */
    public function isOwnerOnly(): bool
    {
        return $this === self::Favorites;
    }

    /**
     * SEO plan 2026-10: every link to the statistics carries rel="nofollow" (robots.txt disallows them)
     */
    public function isNofollow(): bool
    {
        return $this === self::Statistics;
    }
}
