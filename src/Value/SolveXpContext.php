<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Everything the pure XpCalculator needs to know about one solve. The wiring layer
 * (award handler / recompute) is responsible for computing occurrence position, week/day
 * counters and the speed percentile — the calculator itself never touches the database.
 */
final readonly class SolveXpContext
{
    public function __construct(
        /** Pieces count snapshotted at log time (fallback: current puzzle value). */
        public int $piecesCount,
        /** puzzle_difficulty.difficulty_tier 1–6; null = unrated (multiplier 1.00, settled later). */
        public null|int $difficultyTier,
        /** seconds_to_solve IS NOT NULL — relax solves are the untimed ones. */
        public bool $isTimed,
        /** puzzling_type IN (duo, team) — every listed participant earns with the 0.75 multiplier. */
        public bool $isTeamOrDuo,
        public bool $unboxed,
        /**
         * 1-based position of this solve among ALL of the player's solves of this puzzle —
         * both timed and relax — in canonical order (COALESCE(finished_at, tracked_at), id).
         * A timed solve after an earlier relax solve of the same puzzle is occurrence 2.
         */
        public int $occurrenceIndex,
        /** tracked_at before XpCalculator::fullFormulaFrom() — core formula only, no bonuses. */
        public bool $isBackfill,
        public SpeedPercentile $speedPercentile = SpeedPercentile::None,
        /** How many XP-earning solves this player already has in this solve's ISO week (UTC). */
        public int $xpEarningSolvesThisWeek = 0,
        /** True when the player has no earlier XP-earning solve on this solve's UTC day. */
        public bool $isFirstXpEarningSolveOfDay = false,
    ) {
    }
}
