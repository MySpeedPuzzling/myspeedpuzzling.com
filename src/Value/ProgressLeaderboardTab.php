<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The boards of the progression leaderboard page (route xp_leaderboard, ?tab=). The page renders its tabs, titles and
 * values from this list, so a new board is a new case here + its rows in XpLeaderboardController::rows() + its
 * `xp.leaderboard.board.<value>.*` translations.
 */
enum ProgressLeaderboardTab: string
{
    case Xp = 'xp';
    case AchievementPoints = 'achievement-points';

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

    public function translationPrefix(): string
    {
        return 'xp.leaderboard.board.' . $this->value;
    }
}
