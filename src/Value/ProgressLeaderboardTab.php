<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The boards of the progression leaderboard page (route xp_leaderboard, ?tab=). The page renders its tabs, titles and
 * values from this list, so a new board is a new case here + its statement in GetXpLeaderboard::board() + its
 * `xp.leaderboard.board.<value>.*` and `leaderboards.value.<value>` translations.
 */
enum ProgressLeaderboardTab: string
{
    case Xp = 'xp';
    case AchievementPoints = 'achievement-points';
    case Karma = 'karma';

    /** Guests see a sign-in prompt instead of the list */
    public function requiresLogin(): bool
    {
        return $this === self::AchievementPoints;
    }

    /** The level as secondary text next to the value (XP board only - elsewhere it is not what is ranked) */
    public function showsLevel(): bool
    {
        return $this === self::Xp;
    }

    /** Karma has its kind and period filters (templates/karma/_leaderboard_filters.html.twig) */
    public function hasKarmaFilters(): bool
    {
        return $this === self::Karma;
    }

    /**
     * The value column's colour (templates/leaderboards/_value.html.twig, design-spec §4 "Value column"): each board in
     * its progression family's colour
     */
    public function valueFamily(): string
    {
        return match ($this) {
            self::Xp => 'level',
            self::AchievementPoints => 'achievements',
            self::Karma => 'karma',
        };
    }

    public function translationPrefix(): string
    {
        return 'xp.leaderboard.board.' . $this->value;
    }
}
