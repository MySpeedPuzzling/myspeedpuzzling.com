<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * Level curve v6 (Jan, 2026-10-11): Level 50 = 7,000 XP total - about 1,300 logged puzzles including the bonuses.
 * Levels are numeric only — no names. XP keeps accruing in the ledger past 7,000 but no further levels exist.
 *
 * Why (after v5, the same day): "early on the levels jump extremely quickly" (v5: Level 4 after 3 solves, Level 9 after
 * 10) and Level 50 at 7,000 XP. A solve is worth ~6 XP under the formula (median cumulative XP on the production copy:
 * 7 after 1 solve, 65 after 10, 612 after 100, 5,792 after 1,000, 6,726 after 1,250), so a threshold reads as solves.
 * Shape: Level 2 = 5 XP - the first solve's welcome level-up (a first 500-piece solve earns 6-7 XP) - then steps that
 * start at 14 XP and grow evenly (14 + a·(L − 2)², a fitted so Level 50 = 7,000), never smaller than the one before.
 * A typical newcomer: Level 2 after the first solve, 3 after 3, 4 after 5, 6 after 10, 10 after 25, 15 after 50,
 * 20 after 100, 29 after 250, 37 after 500, 46 after 1,000, 50 after ~1,300. Launch projection on the production copy:
 * 8 players at Level 50, 90 at Level 40+, median Level 6 (p90 25). docs/features/xp-levels/launch-2026-10.md §1.
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
        3 => 19,
        4 => 33,
        5 => 48,
        6 => 63,
        7 => 80,
        8 => 99,
        9 => 119,
        10 => 142,
        11 => 167,
        12 => 195,
        13 => 227,
        14 => 263,
        15 => 302,
        16 => 346,
        17 => 395,
        18 => 448,
        19 => 508,
        20 => 573,
        21 => 644,
        22 => 722,
        23 => 807,
        24 => 899,
        25 => 999,
        26 => 1106,
        27 => 1222,
        28 => 1347,
        29 => 1481,
        30 => 1624,
        31 => 1776,
        32 => 1939,
        33 => 2113,
        34 => 2297,
        35 => 2492,
        36 => 2699,
        37 => 2917,
        38 => 3148,
        39 => 3392,
        40 => 3648,
        41 => 3918,
        42 => 4201,
        43 => 4498,
        44 => 4810,
        45 => 5136,
        46 => 5477,
        47 => 5834,
        48 => 6206,
        49 => 6595,
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
