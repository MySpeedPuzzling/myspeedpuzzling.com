<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

/**
 * A puzzle's community distribution from the sorted best time of each solver (analytics-studio.md §2.5) - pure:
 * percentiles (linear interpolation, like Postgres' percentile_cont), a histogram and a time's place among the solvers.
 */
final class AnalyticsStudioDistribution
{
    public const int HISTOGRAM_BUCKETS = 20;

    /**
     * @param list<int> $sortedBests ascending
     */
    public static function percentile(array $sortedBests, float $fraction): null|int
    {
        $count = count($sortedBests);

        if ($count === 0) {
            return null;
        }

        $position = $fraction * ($count - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        $value = $sortedBests[$lower] + ($sortedBests[$upper] - $sortedBests[$lower]) * ($position - $lower);

        return (int) round($value);
    }

    /**
     * @param list<int> $sortedBests ascending
     * @return null|array{p10: int, p25: int, p50: int, p75: int, p90: int}
     */
    public static function percentiles(array $sortedBests): null|array
    {
        if ($sortedBests === []) {
            return null;
        }

        return [
            'p10' => (int) self::percentile($sortedBests, 0.10),
            'p25' => (int) self::percentile($sortedBests, 0.25),
            'p50' => (int) self::percentile($sortedBests, 0.50),
            'p75' => (int) self::percentile($sortedBests, 0.75),
            'p90' => (int) self::percentile($sortedBests, 0.90),
        ];
    }

    /**
     * Equal-width buckets from the fastest time to the 98th percentile (the slow tail would flatten the rest), the
     * last bucket open-ended. Fewer buckets when there are fewer distinct seconds than buckets.
     *
     * @param list<int> $sortedBests ascending
     * @return list<array{fromSeconds: int, count: int}>
     */
    public static function histogram(array $sortedBests, int $buckets = self::HISTOGRAM_BUCKETS): array
    {
        if ($sortedBests === []) {
            return [];
        }

        $from = $sortedBests[0];
        $to = max($from, (int) self::percentile($sortedBests, 0.98));
        $span = $to - $from;
        $buckets = max(1, min($buckets, $span + 1));
        $width = max(1, (int) ceil(($span + 1) / $buckets));

        $counts = array_fill(0, $buckets, 0);

        foreach ($sortedBests as $seconds) {
            $counts[min($buckets - 1, intdiv(max(0, $seconds - $from), $width))]++;
        }

        $histogram = [];
        foreach ($counts as $index => $count) {
            $histogram[] = ['fromSeconds' => $from + $index * $width, 'count' => $count];
        }

        return $histogram;
    }

    /**
     * The place a time would take among the solvers' best times: one more than the solvers strictly faster - ties
     * share a place, like a leaderboard.
     *
     * @param list<int> $sortedBests ascending
     */
    public static function placeOf(array $sortedBests, int $seconds): int
    {
        $low = 0;
        $high = count($sortedBests);

        while ($low < $high) {
            $middle = intdiv($low + $high, 2);

            if ($sortedBests[$middle] < $seconds) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        return $low + 1;
    }
}
