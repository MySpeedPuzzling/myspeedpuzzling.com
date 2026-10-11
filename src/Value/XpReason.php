<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One case per XP-ledger receipt line — the §1.2 ledger decomposition of the XP formula.
 * Base and extras are stored as SEPARATE entries so the post-solve receipt renders 1:1
 * from ledger rows. Every entry comes from a logged puzzle: achievements grant no XP
 * (docs/features/xp-levels/launch-2026-10.md).
 */
enum XpReason: string
{
    case SolveBase = 'solve_base';
    case SolveDifficultyBonus = 'solve_difficulty_bonus';
    case SolveUnboxedBonus = 'solve_unboxed_bonus';
    case SolveSpeedBonus = 'solve_speed_bonus';
    case SolveWeeklyBoost = 'solve_weekly_boost';
    case DifficultySettlement = 'difficulty_settlement';
    case SpeedSettlement = 'speed_settlement';
    case SolveCompensation = 'solve_compensation';

    public function translationKey(): string
    {
        return 'xp.reason.' . $this->value;
    }
}
