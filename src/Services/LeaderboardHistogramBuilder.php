<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Results\LeaderboardHistogram;
use SpeedPuzzling\Web\Results\LeaderboardHistogramBin;

/**
 * Bins a leaderboard's times into the distribution chart of big leaderboards
 * (docs/features/puzzle-leaderboard-chart.md).
 *
 * - Bar width is the narrowest "nice" width (10 s ... 4 h) that keeps the chart at MAX_BINS bars or fewer,
 *   and bars start on a multiple of it, so the axis reads 0:15, 0:20, 0:25 ...
 * - Far outliers - beyond Q1 - 3 IQR and Q3 + 3 IQR - fold into one open-ended bar at either end, so a single
 *   7-hour entry cannot squeeze everybody else into two bars. On real leaderboards that is ~1 % of the rows.
 */
readonly final class LeaderboardHistogramBuilder
{
    public const int MAX_BINS = 30;

    /**
     * Bar widths in seconds, narrowest first
     */
    public const array BIN_WIDTHS = [10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 14400];

    private const float OUTLIER_IQR_FACTOR = 3.0;

    /**
     * @param array<int> $times one time (seconds) per leaderboard row, in any order
     * @param null|int $viewerTime the time of the viewer's own row, if they have one
     * @param array<int> $firstAttemptTimes the times, out of $times, of the rows whose time is a first attempt
     */
    public function build(array $times, null|int $viewerTime = null, array $firstAttemptTimes = []): LeaderboardHistogram
    {
        $times = array_values($times);
        sort($times);
        $total = count($times);

        if ($total === 0) {
            return new LeaderboardHistogram(
                bins: [],
                binWidth: 0,
                total: 0,
                medianTime: null,
                medianPosition: null,
                viewerTime: null,
                viewerBin: null,
                viewerPosition: null,
            );
        }

        $q1 = self::percentile($times, 0.25);
        $q3 = self::percentile($times, 0.75);
        $lowFence = $q1 - self::OUTLIER_IQR_FACTOR * ($q3 - $q1);
        $highFence = $q3 + self::OUTLIER_IQR_FACTOR * ($q3 - $q1);

        // Q1 and Q3 themselves always lie between the fences, so the core is never empty
        $coreMin = $q1;
        $coreMax = $q3;

        foreach ($times as $time) {
            if ($time >= $lowFence && $time < $coreMin) {
                $coreMin = $time;
            }

            if ($time <= $highFence && $time > $coreMax) {
                $coreMax = $time;
            }
        }

        $width = self::chooseWidth($coreMin, $coreMax);
        $start = intdiv($coreMin, $width) * $width;
        $end = (intdiv($coreMax, $width) + 1) * $width;

        $coreBins = intdiv($end - $start, $width);

        // -1 the faster tail, 0 ... $coreBins - 1 the bars in between, $coreBins the slower tail
        $slot = static fn (int $time): int => match (true) {
            $time < $start => -1,
            $time >= $end => $coreBins,
            default => intdiv($time - $start, $width),
        };

        $counts = array_fill(-1, $coreBins + 2, 0);
        $firstAttempts = array_fill(-1, $coreBins + 2, 0);

        foreach ($times as $time) {
            $counts[$slot($time)]++;
        }

        foreach ($firstAttemptTimes as $time) {
            $firstAttempts[$slot($time)]++;
        }

        $bins = [];

        if ($counts[-1] > 0) {
            $bins[] = new LeaderboardHistogramBin(from: null, to: $start, count: $counts[-1], firstAttempts: $firstAttempts[-1]);
        }

        for ($index = 0; $index < $coreBins; $index++) {
            $bins[] = new LeaderboardHistogramBin(
                from: $start + $index * $width,
                to: $start + ($index + 1) * $width,
                count: $counts[$index],
                firstAttempts: $firstAttempts[$index],
            );
        }

        if ($counts[$coreBins] > 0) {
            $bins[] = new LeaderboardHistogramBin(from: $end, to: null, count: $counts[$coreBins], firstAttempts: $firstAttempts[$coreBins]);
        }

        $offset = $counts[-1] > 0 ? 1 : 0;
        $lastIndex = count($bins) - 1;

        $position = static function (int $time) use ($start, $end, $width, $offset, $lastIndex): float {
            if ($time < $start) {
                return 0.5;
            }

            if ($time >= $end) {
                return $lastIndex + 0.5;
            }

            return $offset + ($time - $start) / $width;
        };

        $binIndex = static function (int $time) use ($start, $end, $width, $offset, $lastIndex): int {
            if ($time < $start) {
                return 0;
            }

            if ($time >= $end) {
                return $lastIndex;
            }

            return $offset + intdiv($time - $start, $width);
        };

        // Same rule as the leaderboard's median: the middle row, or the average of the middle two
        $middle = intdiv($total, 2);
        $medianTime = $total % 2 === 0
            ? intdiv($times[$middle - 1] + $times[$middle], 2)
            : $times[$middle];

        return new LeaderboardHistogram(
            bins: $bins,
            binWidth: $width,
            total: $total,
            medianTime: $medianTime,
            medianPosition: $position($medianTime),
            viewerTime: $viewerTime,
            viewerBin: $viewerTime !== null ? $binIndex($viewerTime) : null,
            viewerPosition: $viewerTime !== null ? $position($viewerTime) : null,
        );
    }

    private static function chooseWidth(int $min, int $max): int
    {
        foreach (self::BIN_WIDTHS as $width) {
            if (intdiv($max, $width) + 1 - intdiv($min, $width) <= self::MAX_BINS) {
                return $width;
            }
        }

        return self::BIN_WIDTHS[array_key_last(self::BIN_WIDTHS)];
    }

    /**
     * Nearest-rank percentile of a sorted, non-empty list
     *
     * @param non-empty-list<int> $sortedTimes
     */
    private static function percentile(array $sortedTimes, float $fraction): int
    {
        $index = max(0, (int) ceil($fraction * count($sortedTimes)) - 1);

        return $sortedTimes[$index];
    }
}
