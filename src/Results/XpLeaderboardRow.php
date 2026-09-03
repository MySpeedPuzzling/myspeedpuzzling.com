<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\LevelTable;

readonly final class XpLeaderboardRow
{
    public function __construct(
        public int $rank,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $countryCode,
        public null|string $avatar,
        /** XP total, weekly XP delta or AP — depending on the tab. */
        public int $value,
        public int $level,
        /** AP shown next to "Lv 50" rows — only when the row player is a member (§1.9). */
        public null|int $achievementPoints = null,
    ) {
    }

    public function isMaxLevel(): bool
    {
        return $this->level >= LevelTable::MAX_LEVEL;
    }
}
