<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
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
     * @param array<PuzzleSolver>|array<PuzzleSolversGroup> $solvedPuzzles
     * @return array<string, non-empty-array<PuzzleSolver>>|array<string, non-empty-array<PuzzleSolversGroup>>
     */
    public function groupPlayers(array $solvedPuzzles): array
    {
        $grouped = [];
        /** @var array<string, DateTimeImmutable> $oldest */
        $oldest = [];
        $oldestTimes = [];
        /** @var array<string, bool> $withFirstAttempt */
        $withFirstAttempt = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            if ($solvedPuzzle instanceof PuzzleSolversGroup) {
                $playersIdentification = $this->calculatePlayersIdentification($solvedPuzzle);
            } else {
                $playersIdentification = $solvedPuzzle->playerId;
                $currentlyOldest = $oldest[$solvedPuzzle->playerId] ?? null;

                if ($currentlyOldest === null || $currentlyOldest->getTimestamp() > $solvedPuzzle->finishedAt->getTimestamp()) {
                    $oldest[$playersIdentification] = $solvedPuzzle->finishedAt;
                    $oldestTimes[$playersIdentification] = $solvedPuzzle->timeId;
                }

                if ($solvedPuzzle->firstAttempt === true) {
                    $withFirstAttempt[$playersIdentification] = true;
                }
            }

            $grouped[$playersIdentification][] = $solvedPuzzle;
        }

        foreach ($grouped as $identification => $puzzles) {
            // Find the puzzle with the lowest time and place it at the beginning
            usort($puzzles, static fn(PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int => $a->time <=> $b->time);
            $fastestPuzzle = array_shift($puzzles);

            // Sort the remaining puzzles by finishedAt
            usort($puzzles, static fn(PuzzleSolver|PuzzleSolversGroup $a, PuzzleSolver|PuzzleSolversGroup $b): int => $b->finishedAt <=> $a->finishedAt);

            // Prepend the fastest puzzle to the sorted array
            array_unshift($puzzles, $fastestPuzzle);

            // Update the group with the sorted puzzles
            $grouped[$identification] = $puzzles;
        }

        foreach ($grouped as $identification => $puzzles) {
            $oldestId = $oldestTimes[$identification] ?? null;
            $hasFirstAttempt = $withFirstAttempt[$identification] ?? false;

            if ($hasFirstAttempt === false && $oldestId !== null) {
                foreach ($puzzles as $index => $solvingTime) {
                    if ($solvingTime instanceof PuzzleSolver && $oldestId === $solvingTime->timeId) {
                        $grouped[$identification][$index] = $solvingTime->makeOldest();
                        break;
                    }
                }
            }
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
