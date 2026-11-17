<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Value\RoundEntryResult;

/**
 * The one ranking of official round results (docs/features/competitions-management/official-results.md) - the
 * organiser's tools and the public round page both rank through it, so they never disagree:
 * - finished results by seconds, fastest first;
 * - then unfinished results by pieces placed, most first;
 * - equal results share a rank, the next rank skips ("1, 2, 2, 4");
 * - did not start and no result yet are unranked (null).
 * Computed on every read, never stored. Hiding rows from a viewer (blocklist) happens after ranking, without
 * renumbering - the caller drops rows, never re-ranks the rest.
 */
final readonly class OfficialResultsRanking
{
    /**
     * @template TKey of array-key
     * @param array<TKey, RoundEntryResult> $results
     * @return array<TKey, null|int> the rank of every key, in the input order
     */
    public static function rank(array $results): array
    {
        $ranked = array_filter($results, static fn (RoundEntryResult $result): bool => $result->isRanked());
        uasort($ranked, self::compare(...));

        $ranks = [];
        $position = 0;
        $rank = 0;
        $previous = null;

        foreach ($ranked as $key => $result) {
            $position++;

            if ($previous === null || self::compare($previous, $result) !== 0) {
                $rank = $position;
            }

            $ranks[$key] = $rank;
            $previous = $result;
        }

        $all = [];
        foreach (array_keys($results) as $key) {
            $all[$key] = $ranks[$key] ?? null;
        }

        return $all;
    }

    /**
     * The rank of every result within its group - a division of the round (shared-puzzles-and-divisions.md A13): the
     * rules of rank() inside each group (ties share, did not start and no result unranked), a result without a group
     * (null) is unranked. Like rank(), computed over every entry before a viewer's hidden rows are dropped - never
     * renumbered.
     *
     * @template TKey of array-key
     * @param array<TKey, RoundEntryResult> $results
     * @param array<TKey, null|string> $groupKeyOf the group of every key (a key left out has none)
     * @return array<TKey, null|int> the rank within the group of every key, in the input order
     */
    public static function rankWithin(array $results, array $groupKeyOf): array
    {
        $groups = [];
        foreach ($results as $key => $result) {
            $group = $groupKeyOf[$key] ?? null;

            if ($group !== null) {
                $groups[$group][$key] = $result;
            }
        }

        $ranks = [];
        foreach ($groups as $groupResults) {
            foreach (self::rank($groupResults) as $key => $rank) {
                $ranks[$key] = $rank;
            }
        }

        $all = [];
        foreach (array_keys($results) as $key) {
            $all[$key] = $ranks[$key] ?? null;
        }

        return $all;
    }

    /**
     * The ranking order as a comparator: ranked results (by the rules above), then did not start, then no result.
     * 0 = the same place.
     */
    public static function compare(RoundEntryResult $a, RoundEntryResult $b): int
    {
        $group = self::group($a) <=> self::group($b);

        if ($group !== 0) {
            return $group;
        }

        if ($a->seconds !== null && $b->seconds !== null) {
            return $a->seconds <=> $b->seconds;
        }

        if ($a->piecesPlaced !== null && $b->piecesPlaced !== null) {
            return $b->piecesPlaced <=> $a->piecesPlaced;
        }

        return 0;
    }

    private static function group(RoundEntryResult $result): int
    {
        return match (true) {
            $result->isFinished() => 0,
            $result->isUnfinished() => 1,
            $result->didNotStart => 2,
            default => 3,
        };
    }
}
