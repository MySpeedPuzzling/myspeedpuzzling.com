<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

/**
 * Everything one weekly "Your week in puzzling" email needs, gathered per player.
 */
readonly final class WeeklyDigestData
{
    public function __construct(
        public int $xpGained,
        public int $levelsGained,
        public int $currentLevel,
        /** @var list<array{type: BadgeType, tier: null|BadgeTier}> */
        public array $achievementsEarned,
        public int $solvesCount,
        public int $piecesCount,
        public int $minutesSpent,
        public int $previousSolvesCount,
        public int $previousPiecesCount,
        public int $currentStreakDays,
        /** @var list<array{name: string, solves: int}> */
        public array $favoritesActivity,
        /** @var list<array{type: BadgeType, progress: BadgeProgress}> */
        public array $nextAchievements,
        public null|string $mostSolvedPuzzleName,
        public int $mostSolvedPuzzleSolvers,
    ) {
    }

    public function hadActivity(): bool
    {
        return $this->solvesCount > 0 || $this->xpGained > 0 || $this->achievementsEarned !== [];
    }
}
