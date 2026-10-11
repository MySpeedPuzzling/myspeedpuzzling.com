<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * Level curve v7 (Jan, 2026-10-11): Level 50 = 7,000 XP total - about 1,300 logged puzzles including the bonuses.
 * Levels are numeric only — no names. XP keeps accruing in the ledger past 7,000 but no further levels exist.
 *
 * Why (v5 -> v6 -> v7, the same day): "early on the levels jump extremely quickly", Level 50 at 7,000 XP, then "still
 * increasing the gaps early slightly more". A solve is worth ~6 XP (median cumulative XP on the production copy, the
 * real calculator replaying every player's history with the full formula: 7 after 1 solve, 67 after 10, 617 after 100,
 * 5,787 after 1,000, 6,726 after 1,250), so a threshold reads as solves. Shape: Level 2 = 5 XP - the first solve's
 * welcome level-up (a first 500-piece solve earns 6-7 XP) - then steps that start at 18 XP (v6: 14) and grow evenly
 * (18 + a·(L − 2)², a fitted so Level 50 = 7,000), never smaller than the one before. A typical newcomer: Level 2 after
 * the first solve, 3 after 4, 5 after ~10, 10 after ~27, 14 after 50, 19 after 100, 28 after 250, 36 after 500, 46
 * after 1,000, 50 after ~1,300. Launch projection on the production copy: 8 at Level 50, 88 at Level 40+, median
 * Level 5 (p90 24). docs/features/xp-levels/launch-2026-10.md §1.
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
        3 => 23,
        4 => 41,
        5 => 60,
        6 => 79,
        7 => 100,
        8 => 122,
        9 => 147,
        10 => 173,
        11 => 202,
        12 => 234,
        13 => 269,
        14 => 308,
        15 => 351,
        16 => 398,
        17 => 449,
        18 => 506,
        19 => 568,
        20 => 635,
        21 => 709,
        22 => 789,
        23 => 876,
        24 => 969,
        25 => 1070,
        26 => 1179,
        27 => 1296,
        28 => 1421,
        29 => 1555,
        30 => 1698,
        31 => 1851,
        32 => 2013,
        33 => 2186,
        34 => 2369,
        35 => 2563,
        36 => 2767,
        37 => 2984,
        38 => 3212,
        39 => 3453,
        40 => 3706,
        41 => 3971,
        42 => 4250,
        43 => 4543,
        44 => 4850,
        45 => 5170,
        46 => 5506,
        47 => 5856,
        48 => 6222,
        49 => 6603,
        50 => 7000,
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
