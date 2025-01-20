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
     * @param array<T> $solvedPuzzles
     * @return array<T>
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

            // Oldest first if no first try found
            return $a->finishedAt <=> $b->finishedAt;
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortByFinishedAt(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            if ($a->finishedAt->getTimestamp() === $b->finishedAt->getTimestamp()) {
                return $a->time <=> $b->time;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $solvedPuzzles;
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @param array<T> $solvedPuzzles
     * @return array<T>
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
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterOutNonFirstTriesGrouped(array $groupedSolvers): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: fn (array $grouped): bool => $grouped[0]->firstAttempt === true,
        );
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @param array<T> $solvedPuzzles
     * @return array<string, non-empty-array<T>>
     */
    public function groupPlayers(array $solvedPuzzles): array
    {
        // 1) Group puzzles by single player ID or derived multi-player ID.
        $grouped = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            if ($solvedPuzzle instanceof PuzzleSolversGroup) {
                $playersIdentification = $this->calculatePlayersIdentification($solvedPuzzle);
            } else {
                $playersIdentification = $solvedPuzzle->playerId;
            }

            $grouped[$playersIdentification][] = $solvedPuzzle;
        }

        // For each group, keep the first element as is,
        // Then sort the rest by finishedAt descending (newest first).
        foreach ($grouped as $playerKey => $puzzles) {
            // If only one puzzle, nothing to sort
            if (count($puzzles) > 1) {
                // Shift out the original first item
                $firstItem = array_shift($puzzles);

                // Sort the remaining by finishedAt descending
                usort($puzzles, static function (PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int {
                    return $b->finishedAt <=> $a->finishedAt;
                });

                // Put the first item back on top
                array_unshift($puzzles, $firstItem);
            }

            $grouped[$playerKey] = $puzzles;
        }

        //Now sort the entire $grouped by the time of the first item (ascending).
        uasort($grouped, static function (array $puzzlesA, array $puzzlesB): int {
            // Compare the first puzzle from each group by time
            $fastestA = $puzzlesA[0];
            $fastestB = $puzzlesB[0];

            return $fastestA->time <=> $fastestB->time;
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
