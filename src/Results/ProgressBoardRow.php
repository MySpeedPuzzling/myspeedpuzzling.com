<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;

/**
 * One row of a progress board (Level, Achievement Points, Karma - GetXpLeaderboard::board()).
 */
readonly final class ProgressBoardRow
{
    public function __construct(
        // 0-based place in the list's stable order (value, then id) - what LeaderboardWindow counts in
        public int $position,
        // Shared on equal values (RANK()), within the filtered list
        public int $rank,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $countryCode,
        public null|string $avatar,
        // XP total, AP or karma (the filtered count on a filtered karma board) - what the board ranks
        public int $value,
        public int $level,
        // The value cell's secondary line where the board has a number for it: achievements held (AP board),
        // karma in total (a filtered karma board); null otherwise
        public null|int $secondaryCount = null,
    ) {
    }

    /** The shared leaderboard row (_leaderboard_player.html.twig) reads the country and avatar under these names */
    public function getPlayerCountry(): null|CountryCode
    {
        return $this->countryCode;
    }

    public function getPlayerAvatar(): null|string
    {
        return $this->avatar;
    }
}
