<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The leaderboard distribution chart (docs/features/puzzle-leaderboard-chart.md).
 *
 * Positions are in bar units for the chart's markers: bar i spans [i, i + 1), so 3.5 is the middle
 * of the fourth bar. A time in a folded tail sits in the middle of that bar.
 */
readonly final class LeaderboardHistogram
{
    /**
     * @param list<LeaderboardHistogramBin> $bins
     */
    public function __construct(
        public array $bins,
        public int $binWidth,
        public int $total,
        public null|int $medianTime,
        public null|float $medianPosition,
        public null|int $viewerTime,
        public null|int $viewerBin,
        public null|float $viewerPosition,
    ) {
    }
}
