<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DuplicateResults;

/**
 * A result saved three times is three cases per person (every two of its copies), but one decision: the review
 * page, the recap, the e-mail and the actions all work on **sets** - the groups of results linked by a person's
 * open cases (docs/features/duplicate-results.md, "Review page"). Pure.
 */
final class DuplicateSets
{
    /**
     * @template TKey of array-key
     * @param array<TKey, array{string, string}> $pairs the two result ids of each case
     * @return list<list<TKey>> the keys of the cases of each set; sets in the order of their first case, cases
     *                          in their given order
     */
    public static function group(array $pairs): array
    {
        /** @var array<string, list<TKey>> $casesOfTime */
        $casesOfTime = [];

        foreach ($pairs as $key => [$timeA, $timeB]) {
            $casesOfTime[strtolower($timeA)][] = $key;
            $casesOfTime[strtolower($timeB)][] = $key;
        }

        $setOfCase = [];
        $sets = [];

        foreach ($pairs as $key => $pair) {
            if (isset($setOfCase[$key])) {
                continue;
            }

            $setIndex = count($sets);
            $sets[$setIndex] = [];
            $setOfCase[$key] = $setIndex;
            $queue = [$key];

            while ($queue !== []) {
                $current = array_shift($queue);

                foreach ($pairs[$current] as $timeId) {
                    foreach ($casesOfTime[strtolower($timeId)] as $linked) {
                        if (!isset($setOfCase[$linked])) {
                            $setOfCase[$linked] = $setIndex;
                            $queue[] = $linked;
                        }
                    }
                }
            }
        }

        // Every set lists its cases in the given order, not in the order they were reached
        foreach ($pairs as $key => $pair) {
            $sets[$setOfCase[$key]][] = $key;
        }

        return array_values($sets);
    }

    /**
     * @param array<array{string, string}> $pairs
     * @return list<string> every result id of the pairs, once, lower case
     */
    public static function timeIdsOf(array $pairs): array
    {
        $timeIds = [];

        foreach ($pairs as [$timeA, $timeB]) {
            $timeIds[strtolower($timeA)] = true;
            $timeIds[strtolower($timeB)] = true;
        }

        return array_keys($timeIds);
    }
}
