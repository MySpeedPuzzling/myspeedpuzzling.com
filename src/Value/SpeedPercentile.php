<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How a solo timed solve compares to the puzzle's median time. Computed by the XP wiring
 * (requires a median from ≥3 distinct solvers + plausibility guard passed); None whenever
 * any speed-bonus condition fails.
 */
enum SpeedPercentile: string
{
    case None = 'none';
    case AboveMedian = 'above_median';
    case Top25 = 'top_25';
    case Top10 = 'top_10';

    public function bonusRate(): float
    {
        return match ($this) {
            self::None => 0.0,
            self::AboveMedian => 0.05,
            self::Top25 => 0.10,
            self::Top10 => 0.15,
        };
    }
}
