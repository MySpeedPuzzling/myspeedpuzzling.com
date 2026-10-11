<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use InvalidArgumentException;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\XpAward;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Pure XP formula (§1.2 of the implementation plan, v5 2026-10-11):
 *
 *   core = base × difficulty × team × unboxed × occurrence
 *
 * decomposed into one award per receipt line — base and extras stay SEPARATE:
 *
 *   base_part       = base × team × occurrence                → SolveBase
 *   difficulty_part = base_part × (difficulty − 1)            → SolveDifficultyBonus (tier ≥ 3 only)
 *   unboxed_part    = (base_part + difficulty_part) × 0.20    → SolveUnboxedBonus
 *   core            = base_part + difficulty_part + unboxed_part   (derived, never stored)
 *   speed_part      = core × {0.05|0.10|0.15}                 → SolveSpeedBonus
 *   weekly_part     = core × 0.20 (first 5 solves per week)   → SolveWeeklyBoost
 *
 * Each part is rounded half-up to int independently, and **every part whose condition is met is worth at least
 * 1 XP** - the base whenever core > 0, a bonus whenever the solve qualifies for it (Jan, 2026-10-11: "faster than the
 * median" on a 500-piece puzzle was +5 % of 6 XP = 0.3, rounded to nothing, so a promised bonus never showed up).
 * A bonus the solve does not qualify for produces no award. Backfill solves (tracked before the cutoff,
 * XpFormulaCutoff = the deploy moment) earn only the first three award kinds. Relax repeats earn nothing at all.
 * There is no daily warm-up any more and the weekly boost is +20 % instead of +50 %: the incentives made levels come
 * 1.7× faster than the curve was calibrated for (launch-2026-10.md).
 */
readonly final class XpCalculator
{
    /**
     * Anti-abuse plausibility guard (§1.8): pieces-per-minute above this on a ≥500pc timed
     * solo solve disqualifies the speed bonus (silently — warning log only). World-record
     * pace is ≈17–20 PPM, so no legitimate solve is ever affected.
     */
    public const float MAX_PLAUSIBLE_PPM = 30.0;
    public const int PPM_GUARD_MIN_PIECES = 500;

    /** Speed bonus requires the puzzle median to come from at least this many distinct solvers. */
    public const int SPEED_BONUS_MIN_DISTINCT_SOLVERS = 3;

    public const int WEEKLY_BOOST_SOLVE_LIMIT = 5;

    private const float BASE_MIN = 1.0;
    private const float BASE_MAX = 60.0;
    private const float TEAM_MULTIPLIER = 0.75;
    private const float UNBOXED_BONUS_RATE = 0.20;
    private const float WEEKLY_BOOST_RATE = 0.20;

    /**
     * §1.8 plausibility guard — callers must resolve SpeedPercentile::None (plus a warning
     * log, never any user-facing accusation) when this returns true.
     */
    public static function isImplausiblyFast(int $piecesCount, int $secondsToSolve): bool
    {
        if ($piecesCount < self::PPM_GUARD_MIN_PIECES) {
            return false;
        }

        if ($secondsToSolve <= 0) {
            return true;
        }

        return ($piecesCount / ($secondsToSolve / 60)) > self::MAX_PLAUSIBLE_PPM;
    }

    /**
     * @return list<XpAward>
     */
    public function calculate(SolveXpContext $context): array
    {
        $occurrence = $this->occurrenceMultiplier($context->isTimed, $context->occurrenceIndex);

        if ($occurrence <= 0.0) {
            // Relax repeat — earns nothing, produces no ledger entries.
            return [];
        }

        $base = min(max($context->piecesCount / 100, self::BASE_MIN), self::BASE_MAX);
        $team = $context->isTeamOrDuo ? self::TEAM_MULTIPLIER : 1.0;

        $basePart = $base * $team * $occurrence;
        $difficultyPart = $basePart * ($this->difficultyMultiplier($context->difficultyTier) - 1.0);
        $unboxedPart = $context->unboxed ? ($basePart + $difficultyPart) * self::UNBOXED_BONUS_RATE : 0.0;
        $core = $basePart + $difficultyPart + $unboxedPart;

        $awards = [
            // core > 0 is guaranteed here (base ≥ 1, occurrence > 0)
            new XpAward(XpReason::SolveBase, $this->atLeastOne($basePart)),
        ];

        if ($difficultyPart > 0.0) {
            $awards[] = new XpAward(XpReason::SolveDifficultyBonus, $this->atLeastOne($difficultyPart));
        }

        if ($context->unboxed) {
            $awards[] = new XpAward(XpReason::SolveUnboxedBonus, $this->atLeastOne($unboxedPart));
        }

        if ($context->isBackfill) {
            return $awards;
        }

        // Speed bonus: solo + timed only — defensive re-check even though the wiring
        // already passes SpeedPercentile::None for ineligible solves.
        $speedRate = $context->speedPercentile->bonusRate();
        if ($context->isTimed && $context->isTeamOrDuo === false && $speedRate > 0.0) {
            $awards[] = new XpAward(XpReason::SolveSpeedBonus, $this->atLeastOne($core * $speedRate));
        }

        if ($context->xpEarningSolvesThisWeek < self::WEEKLY_BOOST_SOLVE_LIMIT) {
            $awards[] = new XpAward(XpReason::SolveWeeklyBoost, $this->atLeastOne($core * self::WEEKLY_BOOST_RATE));
        }

        return $awards;
    }

    /**
     * Difficulty multiplier per puzzle_difficulty.difficulty_tier — tiers run 1–6 (not 1–5!).
     * Unrated puzzles (null) count as 1.00 now and settle once when first rated.
     */
    private function difficultyMultiplier(null|int $tier): float
    {
        return match ($tier) {
            null, 1, 2 => 1.0,
            3 => 1.15,
            4 => 1.30,
            5 => 1.40,
            6 => 1.50,
            default => throw new InvalidArgumentException(sprintf('Difficulty tier must be between 1 and 6, got %d.', $tier)),
        };
    }

    /**
     * Occurrence position counts ALL solves of the (player, puzzle) pair in canonical order,
     * regardless of mode; the multiplier then depends on this solve's mode:
     * timed 1st 1.00 · timed 2nd 0.50 · timed 3rd+ 0.25 · relax 1st 0.50 · relax repeat 0.00.
     * There is deliberately NO personal-best exception.
     */
    private function occurrenceMultiplier(bool $isTimed, int $occurrenceIndex): float
    {
        if ($occurrenceIndex < 1) {
            throw new InvalidArgumentException(sprintf('Occurrence index is 1-based, got %d.', $occurrenceIndex));
        }

        if ($isTimed) {
            return match ($occurrenceIndex) {
                1 => 1.0,
                2 => 0.5,
                default => 0.25,
            };
        }

        return $occurrenceIndex === 1 ? 0.5 : 0.0;
    }

    /**
     * A part the solve qualifies for: rounded half-up, never below 1 XP (a promised line always shows up)
     */
    private function atLeastOne(float $value): int
    {
        return max($this->roundHalfUp($value), 1);
    }

    private function roundHalfUp(float $value): int
    {
        // Two-step rounding absorbs binary float representation error (10 × 0.15 gives
        // 1.4999999999999998) — the formula's decimal inputs are exact within 9 decimals,
        // so the intermediate round restores the intended half boundary before the final
        // half-up rounding to int.
        return (int) round(round($value, 9));
    }
}
