<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Players whose stored progression disagrees with their current records, per check (GetProgressionDrift).
 */
readonly final class ProgressionDrift
{
    /**
     * @param list<string> $xpTotalNotLedger
     * @param list<string> $levelNotXp
     * @param list<string> $achievementPointsNotTiers
     * @param list<string> $deletedSolveNotCompensated
     * @param list<string> $staleCompensation
     * @param list<string> $xpWithoutParticipation
     * @param list<string> $baseNotFormula
     * @param list<string> $slotsNotFirstSolves
     */
    public function __construct(
        public array $xpTotalNotLedger,
        public array $levelNotXp,
        public array $achievementPointsNotTiers,
        public array $deletedSolveNotCompensated,
        public array $staleCompensation,
        public array $xpWithoutParticipation,
        public array $baseNotFormula,
        public array $slotsNotFirstSolves,
    ) {
    }

    /**
     * @return array<string, int> check => players
     */
    public function counts(): array
    {
        return [
            'xp_total_not_ledger' => count($this->xpTotalNotLedger),
            'level_not_xp' => count($this->levelNotXp),
            'achievement_points_not_tiers' => count($this->achievementPointsNotTiers),
            'deleted_solve_not_compensated' => count($this->deletedSolveNotCompensated),
            'stale_compensation' => count($this->staleCompensation),
            'xp_without_participation' => count($this->xpWithoutParticipation),
            'base_not_formula' => count($this->baseNotFormula),
            'slots_not_first_solves' => count($this->slotsNotFirstSolves),
        ];
    }

    /**
     * Players whose XP ledger is rebuilt by the repair.
     *
     * @return list<string>
     */
    public function xpPlayerIds(): array
    {
        return array_values(array_unique([
            ...$this->xpTotalNotLedger,
            ...$this->levelNotXp,
            ...$this->deletedSolveNotCompensated,
            ...$this->staleCompensation,
            ...$this->xpWithoutParticipation,
            ...$this->baseNotFormula,
            ...$this->slotsNotFirstSolves,
        ]));
    }

    /**
     * Players whose achievements are evaluated again by the repair.
     *
     * @return list<string>
     */
    public function achievementPlayerIds(): array
    {
        return $this->achievementPointsNotTiers;
    }

    public function isClean(): bool
    {
        return $this->xpPlayerIds() === [] && $this->achievementPlayerIds() === [];
    }
}
