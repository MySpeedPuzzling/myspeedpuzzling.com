<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the launch check found (GetXpLaunchVerification) - the switch flips only when every count is zero.
 */
readonly final class XpLaunchVerification
{
    public function __construct(
        public int $playersWithSolvesWithoutXp,
        public int $xpTotalsNotMatchingLedger,
        public int $levelsNotMatchingXp,
        public int $achievementPointsNotMatchingAchievements,
        public int $playersWithSolves,
    ) {
    }

    public function passed(): bool
    {
        return $this->playersWithSolvesWithoutXp === 0
            && $this->xpTotalsNotMatchingLedger === 0
            && $this->levelsNotMatchingXp === 0
            && $this->achievementPointsNotMatchingAchievements === 0;
    }

    /**
     * @return list<string>
     */
    public function problems(): array
    {
        $problems = [];

        if ($this->playersWithSolvesWithoutXp > 0) {
            $problems[] = sprintf('%d player(s) with solving times have no XP ledger', $this->playersWithSolvesWithoutXp);
        }

        if ($this->xpTotalsNotMatchingLedger > 0) {
            $problems[] = sprintf('%d player(s) whose xp_total is not the sum of their ledger', $this->xpTotalsNotMatchingLedger);
        }

        if ($this->levelsNotMatchingXp > 0) {
            $problems[] = sprintf('%d player(s) whose level does not match their XP', $this->levelsNotMatchingXp);
        }

        if ($this->achievementPointsNotMatchingAchievements > 0) {
            $problems[] = sprintf('%d player(s) whose achievement_points are not the sum of their achievements', $this->achievementPointsNotMatchingAchievements);
        }

        return $problems;
    }
}
