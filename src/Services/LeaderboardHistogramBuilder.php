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
     */
    public function build(array $times, null|int $viewerTime = null): LeaderboardHistogram
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

        $counts = array_fill(0, intdiv($end - $start, $width), 0);
        $faster = 0;
        $slower = 0;

        foreach ($times as $time) {
            if ($time < $start) {
                $faster++;
            } elseif ($time >= $end) {
                $slower++;
            } else {
                $counts[intdiv($time - $start, $width)]++;
            }
        }

        $bins = [];

        if ($faster > 0) {
            $bins[] = new LeaderboardHistogramBin(from: null, to: $start, count: $faster);
        }

        foreach ($counts as $index => $count) {
            $bins[] = new LeaderboardHistogramBin(
                from: $start + $index * $width,
                to: $start + ($index + 1) * $width,
                count: $count,
            );
        }

        if ($slower > 0) {
            $bins[] = new LeaderboardHistogramBin(from: $end, to: null, count: $slower);
        }

        $offset = $faster > 0 ? 1 : 0;
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
