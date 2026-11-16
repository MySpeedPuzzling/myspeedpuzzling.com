<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\UnfinishedPuzzleResult;
use SpeedPuzzling\Web\Value\CountryCode;

/**
 * The bottom rows of a puzzle page tab: unfinished results, after every ranked row, never ranked
 * (docs/features/competitions-management/unfinished-results-plan.md "Puzzle detail page"). One row per player or exact
 * pair/team - the same key as a ranked row (PuzzlesSorter::groupPlayers()) - and only for those without a ranked row:
 * whoever also finished the puzzle keeps their ranked row, their unfinished results go into its history (the result
 * detail). Most pieces placed first; within a row, the attempt with the most pieces leads. The puzzle page's filters
 * apply as they apply to the ranked rows (PuzzlesSorter).
 */
readonly final class UnfinishedResultsBoard
{
    /**
     * @param list<UnfinishedPuzzleResult> $results of one tab (solo, pairs or teams)
     * @param array<string, mixed> $rankedRows the tab's ranked rows before any filter, keyed like groupPlayers()
     * @param null|list<string> $favoritePlayers the "favorites only" filter (the viewer included), null = off
     * @return array<string, non-empty-list<UnfinishedPuzzleResult>>
     */
    public function rows(
        array $results,
        array $rankedRows,
        null|string $viewerId,
        bool $onlyFirstTries = false,
        bool $onlyUnboxed = false,
        null|CountryCode $country = null,
        null|array $favoritePlayers = null,
        bool $onlyMyTeams = false,
    ): array {
        $grouped = [];

        foreach ($results as $result) {
            $key = self::keyOf($result);

            if (array_key_exists($key, $rankedRows)) {
                continue;
            }

            $grouped[$key][] = $result;
        }

        $rows = [];

        foreach ($grouped as $key => $attempts) {
            // A first try / unboxed filter keeps the rows with such an attempt, led by it (PuzzlesSorter::filterGroupedByAttempt())
            $matching = array_values(array_filter(
                $attempts,
                static fn (UnfinishedPuzzleResult $attempt): bool => ($onlyFirstTries === false || $attempt->firstAttempt)
                    && ($onlyUnboxed === false || $attempt->unboxed),
            ));

            if ($matching === []) {
                continue;
            }

            $others = array_values(array_filter(
                $attempts,
                static fn (UnfinishedPuzzleResult $attempt): bool => in_array($attempt, $matching, true) === false,
            ));

            usort($matching, self::compare(...));
            usort($others, self::compare(...));
            $row = [...$matching, ...$others];
            $lead = $row[0];

            if (self::isShownTo($lead, $viewerId) === false) {
                continue;
            }

            if ($country !== null && self::isFrom($lead, $country) === false) {
                continue;
            }

            if ($favoritePlayers !== null && $favoritePlayers !== [] && self::hasAnyOf($lead, $favoritePlayers) === false) {
                continue;
            }

            if ($onlyMyTeams && ($lead->isSolo() || $lead->containsPlayer($viewerId) === false)) {
                continue;
            }

            $rows[$key] = $row;
        }

        uasort($rows, static fn (array $a, array $b): int => self::compare($a[0], $b[0]));

        return $rows;
    }

    /**
     * The key of a ranked row of the same people: the player id (solo), the sorted people of the pair/team (PuzzlesSorter)
     */
    public static function keyOf(UnfinishedPuzzleResult $result): string
    {
        if ($result->isSolo()) {
            return $result->players[0]->playerId ?? $result->timeId;
        }

        return PuzzlesSorter::puzzlersIdentification($result->players);
    }

    private static function compare(UnfinishedPuzzleResult $a, UnfinishedPuzzleResult $b): int
    {
        return $b->piecesPlaced <=> $a->piecesPlaced
            ?: $a->trackedAt <=> $b->trackedAt
            ?: $a->timeId <=> $b->timeId;
    }

    /**
     * PuzzlesSorter::filterOutPrivateProfiles(): solo - not a private player unless it is the viewer; pair/team - unless
     * every member is private and the viewer is none of them (a guest or an anonymous slot is not private)
     */
    private static function isShownTo(UnfinishedPuzzleResult $result, null|string $viewerId): bool
    {
        foreach ($result->players as $player) {
            if ($player->isPrivate === false || ($viewerId !== null && $player->playerId === $viewerId)) {
                return true;
            }
        }

        return false;
    }

    private static function isFrom(UnfinishedPuzzleResult $result, CountryCode $country): bool
    {
        foreach ($result->players as $player) {
            if ($player->playerCountry === $country) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $playerIds
     */
    private static function hasAnyOf(UnfinishedPuzzleResult $result, array $playerIds): bool
    {
        foreach ($result->players as $player) {
            if ($player->playerId !== null && in_array($player->playerId, $playerIds, true)) {
                return true;
            }
        }

        return false;
    }
}
