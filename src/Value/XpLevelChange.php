<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Result of an XP ledger mutation — enough to decide whether to celebrate a level-up.
 */
final readonly class XpLevelChange
{
    public function __construct(
        public int $previousXpTotal,
        public int $newXpTotal,
        public int $previousLevel,
        public int $newLevel,
    ) {
    }

    public function leveledUp(): bool
    {
        return $this->newLevel > $this->previousLevel;
    }

    public function reachedMaxLevel(): bool
    {
        return $this->leveledUp() && $this->newLevel === LevelTable::MAX_LEVEL;
    }
}
