<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The 7 axes of the Insights puzzler profile card. Each player's raw score per axis is ranked against every puzzler
 * with a score on that axis (player_insights_profile). Exact definitions: docs/features/insights/computation.md.
 */
enum InsightsProfileAxis: string
{
    /** Your time against each puzzle's median solo time (last 12 months) */
    case Speed = 'speed';
    /** How much your form moved against a year ago */
    case Improvement = 'improvement';
    /** How close your results stay to your usual: q75 / q25 of your residuals (last 12 months) */
    case Consistency = 'consistency';
    /** How much your second attempt gains, your form taken out */
    case Learning = 'learning';
    /** Your pace against your usual on Hard and Very hard puzzles (last 12 months) */
    case HardPuzzles = 'hard_puzzles';
    /** Your pace against your usual on 1000+ pieces */
    case BigPuzzles = 'big_puzzles';
    /** Your pace against your usual at events */
    case Events = 'events';

    /**
     * Speed, consistency and the pace axes are ratios (lower = faster / steadier); improvement and learning are gains.
     */
    public function higherIsBetter(): bool
    {
        return match ($this) {
            self::Improvement, self::Learning => true,
            default => false,
        };
    }

    /**
     * Results (puzzles for Learning; results in each of the two periods for Improvement) needed for a score
     */
    public function minimumSamples(): int
    {
        return match ($this) {
            self::Consistency => 10,
            self::Learning, self::Improvement => 3,
            default => 5,
        };
    }
}
