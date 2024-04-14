<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;

readonly final class PuzzlesSorter
{
    /**
     * @param array<SolvedPuzzle>|array<PuzzleSolver> $solvedPuzzles
     * @return array<string, non-empty-array<SolvedPuzzle>>|array<string, non-empty-array<PuzzleSolver>>
     */
    public function groupPuzzles(array $solvedPuzzles): array
    {
        $grouped = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            $grouped[$solvedPuzzle->puzzleId][] = $solvedPuzzle;
        }

        foreach ($grouped as $puzzleId => $puzzles) {
            // Find the puzzle with the lowest time and place it at the beginning
            usort($puzzles, static fn(SolvedPuzzle|PuzzleSolver $a, SolvedPuzzle|PuzzleSolver $b): int => $a->time <=> $b->time);
            $fastestPuzzle = array_shift($puzzles);

            // Sort the remaining puzzles by finishedAt
            usort($puzzles, static fn(SolvedPuzzle|PuzzleSolver $a, SolvedPuzzle|PuzzleSolver $b): int => $b->finishedAt <=> $a->finishedAt);

            // Prepend the fastest puzzle to the sorted array
            array_unshift($puzzles, $fastestPuzzle);

            // Update the group with the sorted puzzles
            $grouped[$puzzleId] = $puzzles;
        }

        return $grouped;
    }

    /**
     * @param array<PuzzleSolversGroup> $solvedPuzzles
     * @return array<string, non-empty-array<PuzzleSolversGroup>>
     */
    public function groupGroupPuzzles(array $solvedPuzzles): array
    {
        $grouped = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            $playersIdentification = $this->calculatePlayersIdentification($solvedPuzzle);
            $grouped[$playersIdentification][] = $solvedPuzzle;
        }

        foreach ($grouped as $identification => $puzzles) {
            // Find the puzzle with the lowest time and place it at the beginning
            usort($puzzles, static fn(PuzzleSolversGroup $a, PuzzleSolversGroup $b): int => $a->time <=> $b->time);
            $fastestPuzzle = array_shift($puzzles);

            // Sort the remaining puzzles by finishedAt
            usort($puzzles, static fn(PuzzleSolversGroup $a, PuzzleSolversGroup $b): int => $b->finishedAt <=> $a->finishedAt);

            // Prepend the fastest puzzle to the sorted array
            array_unshift($puzzles, $fastestPuzzle);

            // Update the group with the sorted puzzles
            $grouped[$identification] = $puzzles;
        }

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
