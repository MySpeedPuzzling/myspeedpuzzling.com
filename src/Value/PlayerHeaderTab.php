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
    /** The player's own Insights (docs/features/insights/page.md) - owner-only, and only while InsightsFeature shows it */
    case Insights = 'insights';
    case Calendar = 'calendar';
    case Achievements = 'achievements';
    case Library = 'library';
    case Favorites = 'favorites';

    public function routeName(): string
    {
        return match ($this) {
            self::Profile => 'player_profile',
            self::Statistics => 'player_statistics',
            self::Insights => 'insights',
            self::Calendar => 'activity_calendar',
            self::Achievements => 'player_achievements',
            self::Library => 'puzzle_library',
            self::Favorites => 'player_favorite_puzzlers',
        };
    }

    /**
     * The route parameters of the tab's link for this player's pages - Insights is always the viewer's own page, so its
     * URL carries no player
     *
     * @return array<string, string>
     */
    public function routeParameters(string $playerId): array
    {
        return $this === self::Insights ? [] : ['playerId' => $playerId];
    }

    public function translationKey(): string
    {
        return 'player_header.tabs.' . $this->value;
    }

    /**
     * Whom a player has in favorites (and who has them) is personal, and so are Insights: only the owner gets the page
     * and the tab
     */
    public function isOwnerOnly(): bool
    {
        return $this === self::Favorites || $this === self::Insights;
    }

    /**
     * SEO plan 2026-10: every link to the statistics carries rel="nofollow" (robots.txt disallows them)
     */
    public function isNofollow(): bool
    {
        return $this === self::Statistics;
    }
}
