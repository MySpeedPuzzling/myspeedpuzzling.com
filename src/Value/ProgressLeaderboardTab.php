<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The boards of the progression leaderboard page (route xp_leaderboard, ?tab=). The page renders its tabs, titles and
 * values from this list, so a new board is a new case here + its rows in XpLeaderboardController::rows() (+ its column
 * in GetXpLeaderboard::selfRank(), unless its query brings the viewer's row along like karma's) + its
 * `xp.leaderboard.board.<value>.*` translations.
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

    /** Karma's own query brings the viewer's row along (kind and period filters); the others ask selfRank() */
    public function hasSelfRankQuery(): bool
    {
        return $this !== self::Karma;
    }

    /** Karma has its kind and period filters (templates/karma/_leaderboard_filters.html.twig) */
    public function hasKarmaFilters(): bool
    {
        return $this === self::Karma;
    }

    public function translationPrefix(): string
    {
        return 'xp.leaderboard.board.' . $this->value;
    }
}
