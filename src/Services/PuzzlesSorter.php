<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class PuzzlesSorter
{
    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<string, non-empty-array<SolvedPuzzle>>
     */
    public function groupPuzzles(array $solvedPuzzles): array
    {
        $grouped = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            $grouped[$solvedPuzzle->puzzleId][] = $solvedPuzzle;
        }

        foreach ($grouped as $puzzleId => $puzzles) {
            // Find the puzzle with the lowest time and place it at the beginning
            usort($puzzles, static fn(SolvedPuzzle $a, SolvedPuzzle $b): int => $a->time <=> $b->time);
            $fastestPuzzle = array_shift($puzzles);

            // Sort the remaining puzzles by finishedAt
            usort($puzzles, static fn(SolvedPuzzle $a, SolvedPuzzle $b): int => $b->finishedAt <=> $a->finishedAt);

            // Prepend the fastest puzzle to the sorted array
            array_unshift($puzzles, $fastestPuzzle);

            // Update the group with the sorted puzzles
            $grouped[$puzzleId] = $puzzles;
        }

        return $grouped;
    }


    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @phpstan-param array<T> $solvedPuzzles
     * @phpstan-return array<T>
     */
    public function sortByFirstTry(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
            if ($a->firstAttempt && $b->firstAttempt === false) {
                return -1;
            }
            if ($a->firstAttempt === false && $b->firstAttempt) {
                return 1;
            }

            // Fallback to time comparison
            $timeComparison = $a->time <=> $b->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $solvedPuzzles;
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @phpstan-param array<T> $solvedPuzzles
     * @phpstan-return array<T>
     */
    public function sortByFastest(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
            $timeComparison = $a->time <=> $b->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $solvedPuzzles;
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @phpstan-param array<T> $solvedPuzzles
     * @phpstan-return array<string, non-empty-array<T>>
     */
    public function groupPlayers(array $solvedPuzzles): array
    {
        // 1) Group puzzles by single player ID or derived multi-player ID
        $grouped = [];
        foreach ($solvedPuzzles as $solvedPuzzle) {
            if ($solvedPuzzle instanceof PuzzleSolversGroup) {
                $playersIdentification = $this->calculatePlayersIdentification($solvedPuzzle);
            } else {
                $playersIdentification = $solvedPuzzle->playerId;
            }

            $grouped[$playersIdentification][] = $solvedPuzzle;
        }

        // 2) Sort each group internally by ascending `time`, then (if tied) by ascending `finishedAt`
        foreach ($grouped as $playerKey => $puzzles) {
            usort($puzzles, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
                $timeComparison = $a->time <=> $b->time;

                if ($timeComparison !== 0) {
                    return $timeComparison;
                }
                return $a->finishedAt <=> $b->finishedAt;
            });

            $grouped[$playerKey] = $puzzles;
        }

        // 3) Now sort the entire $grouped array so that the group whose first item is the “fastest”
        //    overall puzzle is first, the next-fastest group is second, etc.
        uasort($grouped, static function (array $puzzlesA, array $puzzlesB): int {
            // Compare the first (fastest) puzzle from each group
            $fastestA = $puzzlesA[0];
            $fastestB = $puzzlesB[0];

            $timeComparison = $fastestA->time <=> $fastestB->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            // If times are tied, compare by finishedAt ascending
            return $fastestA->finishedAt <=> $fastestB->finishedAt;
        });

        return $grouped;
    }

    private function calculatePlayersIdentification(PuzzleSolversGroup $puzzleSolversGroup): string
    {
        $players = [];

        foreach ($puzzleSolversGroup->players as $player) {
            $players[] = $player->playerId ?? $player->playerCode ?? $player->playerName ?? '';
        }

        sort($players);

        return implode('.', $players);
    }
}
