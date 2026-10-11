<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * Level curve v5 (Jan, 2026-10-11): Level 50 = 5,800 XP total - about 1,000 logged puzzles including the bonuses.
 * Levels are numeric only — no names. XP keeps accruing in the ledger past 5,800 but no further levels exist.
 *
 * Why: 5,800 is the median XP the 44 players who have logged 1,000 XP-earning solves would hold at their 1,000th under
 * today's formula (5,793 on the production copy; p25 5,098, p75 6,627). Shape = v4 × (1 + 0.835 × ((L − 1) / 49)^1.5):
 * the bottom stays where v4 had it (Level 2 = 5 XP, so a first 500-piece solve still reaches Level 2; Level 5 = 22),
 * the stretch grows towards the top, and no step is ever smaller than the one before. Projection on the production
 * copy: 18 players at Level 50 at launch (v4: 112), median Level 10 (unchanged); a newcomer reaches Level 9 after ~10
 * solves, Level 27 after ~100 and Level 49-50 after ~1,000. docs/features/xp-levels/launch-2026-10.md §1.
 */
final class LevelTable
{
    public const int MAX_LEVEL = 50;

    /**
     * Cumulative XP required to REACH each level. Level 1 = 0 XP.
     *
     * @var array<int, int>
     */
    private const array CUMULATIVE_XP = [
        1 => 0,
        2 => 5,
        3 => 10,
        4 => 16,
        5 => 22,
        6 => 30,
        7 => 38,
        8 => 47,
        9 => 57,
        10 => 68,
        11 => 81,
        12 => 95,
        13 => 110,
        14 => 127,
        15 => 145,
        16 => 166,
        17 => 187,
        18 => 212,
        19 => 238,
        20 => 268,
        21 => 301,
        22 => 337,
        23 => 377,
        24 => 420,
        25 => 467,
        26 => 520,
        27 => 579,
        28 => 644,
        29 => 716,
        30 => 795,
        31 => 882,
        32 => 979,
        33 => 1085,
        34 => 1202,
        35 => 1330,
        36 => 1471,
        37 => 1627,
        38 => 1797,
        39 => 1985,
        40 => 2192,
        41 => 2419,
        42 => 2669,
        43 => 2943,
        44 => 3245,
        45 => 3577,
        46 => 3943,
        47 => 4343,
        48 => 4783,
        49 => 5267,
        50 => 5800,
    ];

    public static function levelForXp(int $xp): int
    {
        $level = 1;

        foreach (self::CUMULATIVE_XP as $candidate => $threshold) {
            if ($xp < $threshold) {
                break;
            }

            $level = $candidate;
        }

        return $level;
    }

    /**
     * Cumulative XP required to reach the given level.
     */
    public static function xpForLevel(int $level): int
    {
        if (isset(self::CUMULATIVE_XP[$level]) === false) {
            throw new InvalidArgumentException(sprintf('Level must be between 1 and %d, got %d.', self::MAX_LEVEL, $level));
        }

        return self::CUMULATIVE_XP[$level];
    }

    /**
     * Fraction (0.0–1.0) of the way from the current level to the next one.
     * Null at max level — there is nothing to progress toward.
     */
    public static function progressToNext(int $xp): null|float
    {
        $level = self::levelForXp($xp);

        if ($level >= self::MAX_LEVEL) {
            return null;
        }

        $currentThreshold = self::CUMULATIVE_XP[$level];
        $nextThreshold = self::CUMULATIVE_XP[$level + 1];

        return ($xp - $currentThreshold) / ($nextThreshold - $currentThreshold);
    }
}
