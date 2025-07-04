<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\PuzzleSolver;
use SpeedPuzzling\Web\Results\PuzzleSolversGroup;
use SpeedPuzzling\Web\Results\SolvedPuzzle;
use SpeedPuzzling\Web\Value\CountryCode;

readonly final class PuzzlesSorter
{
    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<string, non-empty-array<SolvedPuzzle>>
     */
    public function groupPuzzles(array $solvedPuzzles, bool $withReordering = true): array
    {
        $grouped = [];

        foreach ($solvedPuzzles as $solvedPuzzle) {
            $grouped[$solvedPuzzle->puzzleId][] = $solvedPuzzle;
        }

        if ($withReordering === true) {
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
        }

        return $grouped;
    }


    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle
     * @param array<T> $solvedPuzzles
     * @return array<T>
     */
    public function sortByFirstTry(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle $a, PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle $b): int {
            if ($a->firstAttempt && $b->firstAttempt === false) {
                return -1;
            }
            if ($a->firstAttempt === false && $b->firstAttempt) {
                return 1;
            }

            if ($a instanceof SolvedPuzzle && $b instanceof SolvedPuzzle) {
                return $a->time <=> $b->time;
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
    public function makeFirstAttemptFirst(array $solvedPuzzles): array
    {
        foreach ($solvedPuzzles as $index => $puzzle) {
            if ($puzzle->firstAttempt) {
                // Remove the firstAttempt puzzle from its current position.
                array_splice($solvedPuzzles, $index, 1);
                // Add it to the beginning of the subarray.
                array_unshift($solvedPuzzles, $puzzle);
                // Assuming there's only one firstAttempt per subarray.
                break;
            }
        }

        return array_values($solvedPuzzles);
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
     * @template T of PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle
     * @param array<T> $solvedPuzzles
     * @return array<T>
     */
    public function sortByFastest(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle $a, PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle $b): int {
            $timeComparison = $a->time <=> $b->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    function sortBySlowest(array $solvedPuzzles): array {
        usort($solvedPuzzles, function(SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare in descending order: highest time first.
            return $b->time <=> $a->time;
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    function sortByNewest(array $solvedPuzzles): array {
        usort($solvedPuzzles, function(SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare DateTime objects by timestamp in descending order (newest first).
            return $b->finishedAt->getTimestamp() <=> $a->finishedAt->getTimestamp();
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    function sortByOldest(array $solvedPuzzles): array {
        usort($solvedPuzzles, function(SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare DateTime objects by timestamp in ascending order (oldest first).
            return $a->finishedAt->getTimestamp() <=> $b->finishedAt->getTimestamp();
        });

        return $solvedPuzzles;
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterOutNonFirstTriesGrouped(array $groupedSolvers): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped): bool {
                foreach ($grouped as $solvedPuzzle) {
                    if ($solvedPuzzle->firstAttempt === true) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterByCountry(array $groupedSolvers, CountryCode $countryCode): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped) use ($countryCode): bool {
                if ($grouped[0] instanceof PuzzleSolversGroup) {
                    foreach ($grouped[0]->players as $player) {
                        if ($player->playerCountry === $countryCode) {
                            return true;
                        }
                    }
                }

                if ($grouped[0] instanceof PuzzleSolver) {
                    return $grouped[0]->playerCountry === $countryCode;
                }

                return false;
            }
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

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGroupedByFastest(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups as they are supposed to be
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortByFastest($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result
        usort($groupedSolvedPuzzles, function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $timeComparison = $a->time <=> $b->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $groupedSolvedPuzzles;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGoupedBySlowest(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups as they are supposed to be
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortBySlowest($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result
        usort($groupedSolvedPuzzles, function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $timeComparison = $b->time <=> $a->time;

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return $a->finishedAt <=> $b->finishedAt;
        });

        return $groupedSolvedPuzzles;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGroupedByNewest(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups as they are supposed to be
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortByNewest($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result
        usort($groupedSolvedPuzzles, function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            return $b->finishedAt->getTimestamp() <=> $a->finishedAt->getTimestamp();
        });

        return $groupedSolvedPuzzles;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGroupedByOldest(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups as they are supposed to be
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortByNewest($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result
        usort($groupedSolvedPuzzles, function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            return $a->finishedAt->getTimestamp() <=> $b->finishedAt->getTimestamp();
        });

        return $groupedSolvedPuzzles;
    }
}
