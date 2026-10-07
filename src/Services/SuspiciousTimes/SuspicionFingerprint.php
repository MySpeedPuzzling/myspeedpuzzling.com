<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SuspiciousTimes;

use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * Which entry a check or a decision was about (docs/features/suspicious-time-review.md, "Checks and versions"): the
 * puzzle, its piece count, the time and solo/pair/team with the number of puzzlers. Another fingerprint means another
 * entry - an edit, a move to another puzzle, a merge, a piece-count fix, an SQL repair - so every side door is
 * caught without wiring a single event, and trust lapses with the entry it was given to.
 *
 * The scan computes the very same string in SQL (sql()) - SuspicionFingerprintParityTest keeps the two equal.
 */
final class SuspicionFingerprint
{
    public static function of(string $puzzleId, int $piecesCount, null|int $seconds, PuzzlingType|string $puzzlingType, int $puzzlersCount): string
    {
        return md5(implode('|', [
            strtolower($puzzleId),
            $piecesCount,
            $seconds ?? '',
            $puzzlingType instanceof PuzzlingType ? $puzzlingType->value : $puzzlingType,
            $puzzlersCount,
        ]));
    }

    public static function ofTime(PuzzleSolvingTime $time): string
    {
        return self::of(
            $time->puzzle->id->toString(),
            $time->puzzle->piecesCount,
            $time->secondsToSolve,
            $time->puzzlingType,
            $time->puzzlersCount,
        );
    }

    /**
     * The same fingerprint as an SQL expression over a puzzle_solving_time and a puzzle alias.
     */
    public static function sql(string $timeAlias = 'pst', string $puzzleAlias = 'p'): string
    {
        return "md5({$timeAlias}.puzzle_id::text || '|' || {$puzzleAlias}.pieces_count || '|' || COALESCE({$timeAlias}.seconds_to_solve::text, '') || '|' || {$timeAlias}.puzzling_type || '|' || {$timeAlias}.puzzlers_count)";
    }
}
