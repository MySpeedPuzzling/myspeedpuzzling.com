<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\LevelTable;

readonly final class XpProfile
{
    public function __construct(
        public string $playerId,
        public int $xpTotal,
        public int $level,
        public bool $optedOut,
    ) {
    }

    public function isMaxLevel(): bool
    {
        return $this->level >= LevelTable::MAX_LEVEL;
    }

    /**
     * Fraction (0.0–1.0) toward the next level; null at max level.
     */
    public function progressToNext(): null|float
    {
        return LevelTable::progressToNext($this->xpTotal);
    }

    public function xpIntoCurrentLevel(): int
    {
        return $this->xpTotal - LevelTable::xpForLevel($this->level);
    }

    /**
     * XP still missing to reach the next level; null at max level.
     */
    public function xpToNextLevel(): null|int
    {
        if ($this->isMaxLevel()) {
            return null;
        }

        return LevelTable::xpForLevel($this->level + 1) - $this->xpTotal;
    }
}
