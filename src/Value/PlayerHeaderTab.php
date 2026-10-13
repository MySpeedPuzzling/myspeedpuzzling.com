<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The page tabs of the player header (templates/components/PlayerHeader.html.twig), in their order: Overview · Results
 * · Statistics · Calendar · Achievements · Library · Favorites (owner) - docs/features/navigation README §4.7, PROF-02.
 * See docs/features/player-header.md.
 */
enum PlayerHeaderTab: string
{
    // The profile page itself (player_profile) - "Profile" until PROF-02
    case Overview = 'overview';
    case Results = 'results';
    case Statistics = 'statistics';
    case Calendar = 'calendar';
    case Achievements = 'achievements';
    case Library = 'library';
    case Favorites = 'favorites';

    public function routeName(): string
    {
        return match ($this) {
            self::Overview => 'player_profile',
            self::Results => 'player_results',
            self::Statistics => 'player_statistics',
            self::Calendar => 'activity_calendar',
            self::Achievements => 'player_achievements',
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
