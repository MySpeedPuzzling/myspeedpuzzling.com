<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

/**
 * The ranking of one challenge of a solve-all block (real events W4, world-series-rules.md "Review amendments"): the
 * entries that finished that puzzle with its own time known (its split minus the previous one), fastest first; equal
 * times share a place and the next place skips ("1, 2, 2, 4") - OfficialResultsRanking's rule for times. An entry
 * without that time (not finished, splits not recorded) is unranked (null). Ranked over every entry of the round, before
 * the rows hidden from the viewer are dropped - never renumbered.
 */
final readonly class ChallengeRanking
{
    /**
     * @template TKey of array-key
     * @param array<TKey, null|int> $ownSeconds
     * @return array<TKey, null|int> the place of every key, in the input order
     */
    public static function rank(array $ownSeconds): array
    {
        $timed = array_filter($ownSeconds, static fn (null|int $seconds): bool => $seconds !== null);
        asort($timed);

        $places = [];
        $position = 0;
        $place = 0;
        $previous = null;

        foreach ($timed as $key => $seconds) {
            $position++;

            if ($previous === null || $previous !== $seconds) {
                $place = $position;
            }

            $places[$key] = $place;
            $previous = $seconds;
        }

        $all = [];
        foreach (array_keys($ownSeconds) as $key) {
            $all[$key] = $places[$key] ?? null;
        }

        return $all;
    }
}
