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
     * Compare two nullable times, placing null values at the end (ascending order).
     * Returns negative if $a should come first, positive if $b should come first.
     */
    private static function compareTimesAscending(null|int $a, null|int $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // null goes to end
        }
        if ($b === null) {
            return -1; // null goes to end
        }

        return $a <=> $b;
    }

    /**
     * Compare two nullable times, placing null values at the end (descending order).
     * Returns negative if $a should come first, positive if $b should come first.
     */
    private static function compareTimesDescending(null|int $a, null|int $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // null goes to end
        }
        if ($b === null) {
            return -1; // null goes to end
        }

        return $b <=> $a;
    }

    /**
     * Get effective finishedAt for sorting, falling back to trackedAt for SolvedPuzzle.
     */
    private static function getEffectiveFinishedAt(PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle $item): \DateTimeImmutable
    {
        if ($item->finishedAt !== null) {
            return $item->finishedAt;
        }

        if ($item instanceof SolvedPuzzle) {
            return $item->trackedAt;
        }

        return new \DateTimeImmutable('@0');
    }

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
                usort($puzzles, static fn(SolvedPuzzle $a, SolvedPuzzle $b): int => self::compareTimesAscending($a->time, $b->time));
                $fastestPuzzle = array_shift($puzzles);

                // Sort the remaining puzzles by finishedAt
                usort($puzzles, static fn(SolvedPuzzle $a, SolvedPuzzle $b): int => ($b->finishedAt ?? $b->trackedAt) <=> ($a->finishedAt ?? $a->trackedAt));

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
                return self::compareTimesAscending($a->time, $b->time);
            }

            // Oldest first if no first try found
            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
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
            $aDate = self::getEffectiveFinishedAt($a);
            $bDate = self::getEffectiveFinishedAt($b);

            if ($aDate->getTimestamp() === $bDate->getTimestamp()) {
                return self::compareTimesAscending($a->time, $b->time);
            }

            return $aDate <=> $bDate;
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
            $timeComparison = self::compareTimesAscending($a->time, $b->time);

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortBySlowest(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare in descending order: highest time first, nulls at end.
            return self::compareTimesDescending($a->time, $b->time);
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortByNewest(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare DateTime objects by timestamp in descending order (newest first).
            return self::getEffectiveFinishedAt($b)->getTimestamp() <=> self::getEffectiveFinishedAt($a)->getTimestamp();
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortByOldest(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            // Compare DateTime objects by timestamp in ascending order (oldest first).
            return self::getEffectiveFinishedAt($a)->getTimestamp() <=> self::getEffectiveFinishedAt($b)->getTimestamp();
        });

        return $solvedPuzzles;
    }

    /**
     * Calculate PPM (pieces per minute) for a solved puzzle.
     * Returns null if time is null.
     */
    private static function calculatePpm(SolvedPuzzle $puzzle): null|float
    {
        if ($puzzle->time === null || $puzzle->time === 0) {
            return null;
        }

        return ($puzzle->piecesCount * 60) / $puzzle->time;
    }

    /**
     * Compare two PPMs in descending order (highest PPM first, nulls at end).
     * Higher PPM = faster solver.
     */
    private static function comparePpmDescending(null|float $a, null|float $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // null goes to end
        }
        if ($b === null) {
            return -1; // null goes to end
        }

        return $b <=> $a;
    }

    /**
     * Compare two PPMs in ascending order (lowest PPM first, nulls at end).
     */
    private static function comparePpmAscending(null|float $a, null|float $b): int
    {
        if ($a === null && $b === null) {
            return 0;
        }
        if ($a === null) {
            return 1; // null goes to end
        }
        if ($b === null) {
            return -1; // null goes to end
        }

        return $a <=> $b;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortByFastestPpm(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            $ppmA = self::calculatePpm($a);
            $ppmB = self::calculatePpm($b);

            $ppmComparison = self::comparePpmDescending($ppmA, $ppmB);

            if ($ppmComparison !== 0) {
                return $ppmComparison;
            }

            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<SolvedPuzzle> $solvedPuzzles
     * @return array<SolvedPuzzle>
     */
    public function sortBySlowestPpm(array $solvedPuzzles): array
    {
        usort($solvedPuzzles, static function (SolvedPuzzle $a, SolvedPuzzle $b): int {
            $ppmA = self::calculatePpm($a);
            $ppmB = self::calculatePpm($b);

            return self::comparePpmAscending($ppmA, $ppmB);
        });

        return $solvedPuzzles;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGroupedByFastestPpm(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups by PPM
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortByFastestPpm($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result's PPM
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $ppmA = self::calculatePpm($a);
            $ppmB = self::calculatePpm($b);

            $ppmComparison = self::comparePpmDescending($ppmA, $ppmB);

            if ($ppmComparison !== 0) {
                return $ppmComparison;
            }

            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
        });

        return $groupedSolvedPuzzles;
    }

    /**
     * @param array<array<SolvedPuzzle>> $groupedSolvedPuzzles
     * @return array<array<SolvedPuzzle>>
     */
    public function sortGroupedBySlowestPpm(array $groupedSolvedPuzzles, bool $onlyFirstTries): array
    {
        // 1) Sort times within groups by PPM (slowest first)
        foreach ($groupedSolvedPuzzles as $index => $solvedPuzzle) {
            $groupedSolvedPuzzles[$index] = $this->sortBySlowestPpm($solvedPuzzle);

            if ($onlyFirstTries === true) {
                $groupedSolvedPuzzles[$index] = $this->makeFirstAttemptFirst($groupedSolvedPuzzles[$index]);
            }
        }

        // 2) Sort groups by first result's PPM (lowest first)
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $ppmA = self::calculatePpm($a);
            $ppmB = self::calculatePpm($b);

            return self::comparePpmAscending($ppmA, $ppmB);
        });

        return $groupedSolvedPuzzles;
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
     * @template T of PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterOutNonUnboxedGrouped(array $groupedSolvers): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped): bool {
                foreach ($grouped as $solvedPuzzle) {
                    if ($solvedPuzzle->unboxed === true) {
                        return true;
                    }
                }

                return false;
            },
        );
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup|SolvedPuzzle
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterByFirstAttemptAndUnboxedGrouped(array $groupedSolvers): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped): bool {
                foreach ($grouped as $solvedPuzzle) {
                    if ($solvedPuzzle->firstAttempt === true && $solvedPuzzle->unboxed === true) {
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
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @param array<string> $favoritePlayers
     * @return array<string, non-empty-array<T>>
     */
    public function filterByFavoritePlayers(array $groupedSolvers, array $favoritePlayers): array
    {
        if ($favoritePlayers === []) {
            return $groupedSolvers;
        }

        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped) use ($favoritePlayers): bool {
                if ($grouped[0] instanceof PuzzleSolversGroup) {
                    foreach ($grouped[0]->players as $player) {
                        if ($player->playerId !== null && in_array($player->playerId, $favoritePlayers, true)) {
                            return true;
                        }
                    }

                    return false;
                }

                // PuzzleSolver case
                return in_array($grouped[0]->playerId, $favoritePlayers, true);
            }
        );
    }

    /**
     * @template T of PuzzleSolver|PuzzleSolversGroup
     * @param array<string, non-empty-array<T>> $groupedSolvers
     * @return array<string, non-empty-array<T>>
     */
    public function filterOutPrivateProfiles(array $groupedSolvers, null|string $loggedPlayerId): array
    {
        return array_filter(
            array: $groupedSolvers,
            callback: function (array $grouped) use ($loggedPlayerId): bool {
                // For group/team puzzles
                if ($grouped[0] instanceof PuzzleSolversGroup) {
                    $loggedUserInGroup = false;
                    $allPrivate = true;

                    foreach ($grouped[0]->players as $player) {
                        // Check if logged user is in the group
                        if ($player->playerId === $loggedPlayerId) {
                            $loggedUserInGroup = true;
                        }
                        // Check if at least one player is not private
                        if ($player->isPrivate === false) {
                            $allPrivate = false;
                        }
                    }

                    // Show if logged user is in the group, or if not all players are private
                    if ($loggedUserInGroup || $allPrivate === false) {
                        return true;
                    }

                    // Hide only if all players are private and logged user is not in the group
                    return false;
                }

                // For solo puzzles (PuzzleSolver)
                // Filter out if private and not the logged user
                if ($grouped[0]->isPrivate && $grouped[0]->playerId !== $loggedPlayerId) {
                    return false;
                }
                return true;
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
                    return self::getEffectiveFinishedAt($b) <=> self::getEffectiveFinishedAt($a);
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

            return self::compareTimesAscending($fastestA->time, $fastestB->time);
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
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $timeComparison = self::compareTimesAscending($a->time, $b->time);

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
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
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            $timeComparison = self::compareTimesDescending($a->time, $b->time);

            if ($timeComparison !== 0) {
                return $timeComparison;
            }

            return self::getEffectiveFinishedAt($a) <=> self::getEffectiveFinishedAt($b);
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
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            return self::getEffectiveFinishedAt($b)->getTimestamp() <=> self::getEffectiveFinishedAt($a)->getTimestamp();
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
        usort($groupedSolvedPuzzles, static function (array $groupedA, array $groupedB): int {
            /** @var non-empty-array<SolvedPuzzle> $groupedA */
            /** @var non-empty-array<SolvedPuzzle> $groupedB */

            $a = $groupedA[array_key_first($groupedA)];
            $b = $groupedB[array_key_first($groupedB)];

            return self::getEffectiveFinishedAt($a)->getTimestamp() <=> self::getEffectiveFinishedAt($b)->getTimestamp();
        });

        return $groupedSolvedPuzzles;
    }
}
