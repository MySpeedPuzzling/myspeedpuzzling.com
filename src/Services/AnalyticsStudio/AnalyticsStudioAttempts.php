<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use SpeedPuzzling\Web\Results\AnalyticsStudioAttempt;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * Folds a member's (or a line-up's) earlier attempts on one puzzle into the studio's numbers (analytics-studio.md §2.6)
 * - pure. A solve is an attempt that is not unfinished (CountsAsSolve); times only from attempts with a time; "first"
 * and "last" by the solve moment.
 */
final class AnalyticsStudioAttempts
{
    /**
     * @param list<AnalyticsStudioAttempt> $attempts one member's (or line-up's) attempts on one puzzle, any order
     * @return array{solves: int, firstSeconds: null|int, bestSeconds: null|int, lastSeconds: null|int, firstTrySeconds: null|int, unfinished: int, lastSolvedAtMs: null|int, lastSolvedAtPrecision: null|string}
     */
    public static function summary(array $attempts): array
    {
        usort($attempts, static fn (AnalyticsStudioAttempt $a, AnalyticsStudioAttempt $b): int => $a->solvedAt <=> $b->solvedAt);

        $solves = 0;
        $unfinished = 0;
        $first = null;
        $best = null;
        $last = null;
        $firstTry = null;
        $lastSolved = null;

        foreach ($attempts as $attempt) {
            if ($attempt->countsAsSolve() === false) {
                $unfinished++;
                continue;
            }

            $solves++;
            $lastSolved = $attempt;

            if ($attempt->seconds === null) {
                continue;
            }

            $first ??= $attempt->seconds;
            $last = $attempt->seconds;
            $best = $best === null ? $attempt->seconds : min($best, $attempt->seconds);

            if ($attempt->firstAttempt && $firstTry === null) {
                $firstTry = $attempt->seconds;
            }
        }

        return [
            'solves' => $solves,
            'firstSeconds' => $first,
            'bestSeconds' => $best,
            'lastSeconds' => $last,
            'firstTrySeconds' => $firstTry,
            'unfinished' => $unfinished,
            'lastSolvedAtMs' => $lastSolved !== null ? RoundClock::milliseconds($lastSolved->solvedAt) : null,
            'lastSolvedAtPrecision' => $lastSolved?->precision->value,
        ];
    }

    /**
     * One attempt as the entrant detail lists it - a month/year date is shown at its precision, never as a day.
     *
     * `kind` = solo | pair | team.
     *
     * @return array{solvedAtMs: int, solvedAtPrecision: string, seconds: null|int, piecesPlaced: null|int, firstAttempt: bool, kind: string, unboxed: bool}
     */
    public static function toJson(AnalyticsStudioAttempt $attempt): array
    {
        return [
            'solvedAtMs' => RoundClock::milliseconds($attempt->solvedAt),
            'solvedAtPrecision' => $attempt->precision->value,
            'seconds' => $attempt->seconds,
            'piecesPlaced' => $attempt->piecesPlaced,
            'firstAttempt' => $attempt->firstAttempt,
            'kind' => $attempt->kind === 'duo' ? 'pair' : $attempt->kind,
            'unboxed' => $attempt->unboxed,
        ];
    }
}
