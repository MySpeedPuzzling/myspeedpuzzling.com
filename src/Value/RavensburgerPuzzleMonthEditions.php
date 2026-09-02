<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Editions of the Ravensburger "Puzzle Month" - the puzzle boxes carry a printed,
 * human-readable link (/ravensburger-puzzle-month/{edition}) that must keep working
 * forever, so the edition -> puzzle mapping lives in code, not in the database.
 *
 * Each edition points at a puzzle that is created as a hidden placeholder long
 * before the box is printed. See docs/ravensburger-puzzle-month.md for the runbook.
 */
final class RavensburgerPuzzleMonthEditions
{
    /** @var array<int, string> edition number => puzzle id */
    public const array PUZZLE_IDS = [
        1 => '01a0641a-67fd-72f5-859f-31c3b91ae584',
    ];

    public static function puzzleId(int $edition): null|string
    {
        return self::PUZZLE_IDS[$edition] ?? null;
    }
}
