<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

/**
 * An unfinished result - pieces placed when a competition round's time ran out (`pieces_placed IS NOT NULL`, never a
 * `seconds_to_solve`) - is not a solve (docs/features/competitions-management/unfinished-results-plan.md "Refined
 * 2026-11-16", D9 in docs/features/event-results-import/README.md):
 *
 * - counts of completed puzzles ("Completed N×", solved flags, most solved, most active, unsolved lists, XP and
 *   achievement progress…) leave it out: condition();
 * - "pieces puzzled" sums take its pieces placed instead of the puzzle's pieces count: piecesPuzzled();
 * - "time spent puzzling" sums take its `seconds_spent` instead of the time to solve: secondsPuzzled().
 *
 * Performance readers (times, leaderboards, ranks, medians, difficulty, skill, rating, predictions) need none of it:
 * they read `seconds_to_solve IS NOT NULL`, which an unfinished result never has (PuzzleSolvingTime's invariant).
 * A relax solve (no time, no pieces placed) is a solve as before. Every reader of puzzle_solving_time either uses this
 * class or is classified in SolvingTimeReadersAreClassifiedTest.
 */
readonly final class CountsAsSolve
{
    /**
     * condition() for the usual alias `pst` = puzzle_solving_time, for SQL built in constants.
     */
    public const string SQL_CONDITION = '(pst.pieces_placed IS NULL)';

    /**
     * WHERE / FILTER fragment: the row of `$alias` (a puzzle_solving_time alias, or a CTE carrying `pieces_placed`) is
     * a solve. Wrapped in parentheses, safe behind NOT or next to OR.
     */
    public static function condition(string $alias): string
    {
        return "({$alias}.pieces_placed IS NULL)";
    }

    /**
     * The pieces a row puzzled: its pieces placed for an unfinished result, else `$piecesCount` (the expression the
     * reader summed so far - `p.pieces_count`, `COALESCE(pst.pieces_count_snapshot, p.pieces_count)`…).
     */
    public static function piecesPuzzled(string $alias, string $piecesCount): string
    {
        return "COALESCE({$alias}.pieces_placed, {$piecesCount})";
    }

    /**
     * The seconds a row puzzled: its time to solve, or the time spent on an unfinished result - NULL for a relax solve,
     * which SUM skips exactly as before.
     */
    public static function secondsPuzzled(string $alias): string
    {
        return "COALESCE({$alias}.seconds_to_solve, {$alias}.seconds_spent)";
    }
}
