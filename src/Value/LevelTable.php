<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;

/**
 * Level curve v4 (locked): Level 50 = 3,160 XP total. Levels are numeric only — no names.
 * XP keeps accruing in the ledger past 3,160 but no further levels exist.
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
        6 => 29,
        7 => 37,
        8 => 45,
        9 => 54,
        10 => 64,
        11 => 75,
        12 => 87,
        13 => 100,
        14 => 114,
        15 => 129,
        16 => 145,
        17 => 162,
        18 => 181,
        19 => 201,
        20 => 223,
        21 => 247,
        22 => 273,
        23 => 301,
        24 => 331,
        25 => 363,
        26 => 399,
        27 => 438,
        28 => 480,
        29 => 526,
        30 => 576,
        31 => 630,
        32 => 689,
        33 => 753,
        34 => 822,
        35 => 897,
        36 => 978,
        37 => 1066,
        38 => 1161,
        39 => 1264,
        40 => 1376,
        41 => 1497,
        42 => 1628,
        43 => 1770,
        44 => 1924,
        45 => 2091,
        46 => 2272,
        47 => 2468,
        48 => 2680,
        49 => 2910,
        50 => 3160,
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
