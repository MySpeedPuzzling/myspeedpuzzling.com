<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Statistics;

readonly final class OverallStatistics
{
    public SolvedPuzzleStatistics $solvedPuzzle;
    public TimeSpentSolvingStatistics $timeSpentSolving;
    /** @var array<int, int> */
    public array $perPiecesCount;
    public int $totalPieces;
    public int $longestStreak;

    public function __construct(PerCategoryStatistics ...$statistics)
    {
        /** @var non-empty-array<int, int> $piecesGroups */
        $piecesGroups = [];
        /** @var array<string, int> $countPerManufacturer */
        $countPerManufacturer = [];
        /** @var array<string, int> $timePerDay */
        $timePerDay = [];
        /** @var array<string, true> $activeDays */
        $activeDays = [];
        $totalPieces = 0;
        $totalSolvedPuzzleCount = 0;

        foreach ($statistics as $statistic) {
            $totalPieces += $statistic->totalPieces;
            $totalSolvedPuzzleCount += $statistic->solvedPuzzle->count;

            foreach ($statistic->activeDays as $day) {
                $activeDays[$day] = true;
            }

            foreach ($statistic->perPieces as $perPieces) {
                if (!isset($piecesGroups[$perPieces->pieces])) {
                    $piecesGroups[$perPieces->pieces] = 0;
                }

                $piecesGroups[$perPieces->pieces] += $perPieces->count;
            }

            foreach ($statistic->solvedPuzzle->countPerManufacturer as $manufacturerName => $count) {
                if (!isset($countPerManufacturer[$manufacturerName])) {
                    $countPerManufacturer[$manufacturerName] = 0;
                }

                $countPerManufacturer[$manufacturerName] += $count;
            }

            foreach ($statistic->timeSpentSolving->perDay as $day => $time) {
                if (!isset($timePerDay[$day])) {
                    $timePerDay[$day] = 0;
                }

                $timePerDay[$day] += $time;
            }
        }

        ksort($timePerDay);

        // Streak is computed over every active day (including relax-only days that have no
        // measured time), not over $timePerDay — otherwise a relax-only day would be treated
        // as a gap and break the streak. This mirrors the activity calendar's streak.
        $streakDays = array_keys($activeDays);
        sort($streakDays);

        $currentStreak = 0;
        $longestStreak = 0;
        $previousDate = null;

        foreach ($streakDays as $dateStr) {
            $currentDate = new \DateTimeImmutable($dateStr);
            if ($previousDate !== null && $currentDate->format('Y-m-d') === $previousDate->modify('+1 day')->format('Y-m-d')) {
                $currentStreak++;
            } else {
                $currentStreak = 1;
            }
            $previousDate = $currentDate;
            $longestStreak = max($longestStreak, $currentStreak);
        }

        // Sorting perPieces by count, keep keys
        arsort($piecesGroups);

        // Sorting manufacturers, keep keys
        arsort($countPerManufacturer);

        $this->perPiecesCount = $piecesGroups;
        $this->totalPieces = $totalPieces;
        $this->timeSpentSolving = new TimeSpentSolvingStatistics($timePerDay);
        $this->longestStreak = $longestStreak;
        $this->solvedPuzzle = new SolvedPuzzleStatistics($totalSolvedPuzzleCount, $countPerManufacturer);
    }
}
