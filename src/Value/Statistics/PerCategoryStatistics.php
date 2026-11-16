<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Statistics;

use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\DatePrecision;

readonly final class PerCategoryStatistics
{
    public SolvedPuzzleStatistics $solvedPuzzle;

    public TimeSpentSolvingStatistics $timeSpentSolving;

    /** @var array<PiecesStatistics> */
    public array $perPieces;

    public int $totalPieces;

    /**
     * Unique 'Y-m-d' strings of every day the player solved a puzzle, including relax
     * solves (which have no measured time). Used for the activity streak so a relax-only
     * day does not break it — mirrors the activity calendar, which also counts relax days.
     *
     * @var list<string>
     */
    public array $activeDays;

    public function __construct(
        /** @var array<SolvedPuzzle> */
        private array $results,
    ) {
        /** @var non-empty-array<int, array<int>> $piecesGroups */
        $piecesGroups = [];
        $timePerDay = [];
        /** @var array<string, true> $activeDays */
        $activeDays = [];
        $totalPieces = 0;
        /** @var array<string, int> $countPerManufacturer */
        $countPerManufacturer = [];

        // A suspicious result is listed on the profile, but no statistic counts it (docs/features/suspicious-times.md)
        $counted = array_filter($this->results, static fn (SolvedPuzzle $result): bool => $result->suspicious === false);

        foreach ($counted as $result) {
            $piecesCount = $result->piecesCount;
            $time = $result->time;
            $manufacturerName = $result->manufacturerName;
            $finishedDate = ($result->finishedAt ?? $result->trackedAt)->format('Y-m-d');

            // An unfinished result (Query\CountsAsSolve) puzzled its pieces placed, and is no solve
            $totalPieces += $result->piecesPlaced ?? $piecesCount;

            // A result known to its month or year only is on no day: it counts in every total below, and neither as an
            // active day (the streak) nor in the time spent per day (docs/features/data-import/date-precision.md)
            $hasDay = $result->finishedAt === null || $result->datePrecision === DatePrecision::Day;

            // A relax solve (null time) still counts as an active day for the streak,
            // even though it contributes nothing to the time-spent-per-day totals below.
            if ($hasDay) {
                $activeDays[$finishedDate] = true;
            }

            // An unfinished result has no time to solve, only its time spent - time spent puzzling, never a time
            if ($time === null && $result->secondsSpent !== null && $hasDay) {
                $timePerDay[$finishedDate] = ($timePerDay[$finishedDate] ?? 0) + $result->secondsSpent;
            }

            if ($result->isSolve() === false) {
                continue;
            }

            if ($time !== null) {
                if (!isset($piecesGroups[$piecesCount])) {
                    $piecesGroups[$piecesCount] = [];
                }

                $piecesGroups[$piecesCount][] = $time;

                // Grouping time spent per day
                if ($hasDay) {
                    if (!isset($timePerDay[$finishedDate])) {
                        $timePerDay[$finishedDate] = 0;
                    }

                    $timePerDay[$finishedDate] += $time;
                }
            }

            if (!isset($countPerManufacturer[$manufacturerName])) {
                $countPerManufacturer[$manufacturerName] = 0;
            }

            $countPerManufacturer[$manufacturerName]++;
        }

        // Creating PiecesStatistics objects
        $perPieces = [];
        foreach ($piecesGroups as $pieces => $times) {
            /** @var non-empty-array<int> $times */

            $fastestTime = (int) min($times);
            $averageTime = (int) round(array_sum($times) / count($times));

            $perPieces[] = new PiecesStatistics(
                pieces: $pieces,
                count: count($times),
                fastestTime: $fastestTime,
                averageTime: $averageTime,
            );
        }

        // Sorting perPieces by count
        usort($perPieces, static fn (PiecesStatistics $a, PiecesStatistics $b) => $b->count <=> $a->count);

        // Sorting manufacturers, keep keys
        arsort($countPerManufacturer);

        $this->perPieces = $perPieces;
        $this->totalPieces = $totalPieces;
        $this->activeDays = array_keys($activeDays);
        $this->timeSpentSolving = new TimeSpentSolvingStatistics($timePerDay);
        $this->solvedPuzzle = new SolvedPuzzleStatistics(
            count(array_filter($counted, static fn (SolvedPuzzle $result): bool => $result->isSolve())),
            $countPerManufacturer,
        );
    }
}
