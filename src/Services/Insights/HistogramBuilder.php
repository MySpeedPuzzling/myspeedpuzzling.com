<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsHistogramBin;
use SpeedPuzzling\Web\Results\Insights\InsightsHistogramSection;

/**
 * "Where am I way off the median?" - your results binned by percent faster than the puzzle's median solver (5-point
 * bars from −60 to +60 %, the end bars holding the rest), and the outliers more than 30 % off your usual (residual
 * > 1.3 or < 1 ÷ 1.3), each with its "why" situations. The prototype's renderMedian().
 */
readonly final class HistogramBuilder
{
    public const int RANGE_PERCENT = 60;

    public const int BIN_PERCENT = 5;

    /** More than 30 % off your usual */
    public const float OUTLIER_FACTOR = 1.3;

    public const int MIN_RESULTS = 5;

    /**
     * @param list<InsightsRow> $rows the selection
     */
    public function build(array $rows): InsightsHistogramSection
    {
        $binCount = intdiv(2 * self::RANGE_PERCENT, self::BIN_PERCENT);
        $counts = array_fill(0, $binCount, 0);
        $percents = [];
        $slowerThanMedian = 0;
        $slow = [];
        $fast = [];

        foreach ($rows as $row) {
            if ($row->ratio === null) {
                continue;
            }

            $percent = (1 - $row->ratio) * 100;
            $percents[] = $percent;
            $clamped = max(-self::RANGE_PERCENT + 0.1, min(self::RANGE_PERCENT - 0.1, $percent));
            $counts[(int) floor(($clamped + self::RANGE_PERCENT) / self::BIN_PERCENT)]++;

            if ($row->ratio > 1) {
                $slowerThanMedian++;
            }

            if ($row->residual !== null && $row->residual > self::OUTLIER_FACTOR) {
                $slow[] = $row;
            } elseif ($row->residual !== null && $row->residual < 1 / self::OUTLIER_FACTOR) {
                $fast[] = $row;
            }
        }

        $bins = [];

        foreach ($counts as $index => $count) {
            $from = -self::RANGE_PERCENT + $index * self::BIN_PERCENT;
            $bins[] = new InsightsHistogramBin($from, $from + self::BIN_PERCENT, $count);
        }

        usort($slow, static fn (InsightsRow $a, InsightsRow $b): int => $b->residual <=> $a->residual);
        usort($fast, static fn (InsightsRow $a, InsightsRow $b): int => $a->residual <=> $b->residual);

        return new InsightsHistogramSection(
            bins: $bins,
            usualPercent: $percents !== [] ? InsightsMath::median($percents) : null,
            slowerThanMedianCount: $slowerThanMedian,
            sampleSize: count($percents),
            slowOutlierCount: count($slow),
            fastOutlierCount: count($fast),
            slowOutliers: array_map(static fn (InsightsRow $row) => $row->toItem(), array_slice($slow, 0, InsightsHistogramSection::LIST_LIMIT)),
            fastOutliers: array_map(static fn (InsightsRow $row) => $row->toItem(), array_slice($fast, 0, InsightsHistogramSection::LIST_LIMIT)),
            notSureYet: count($percents) < self::MIN_RESULTS,
        );
    }
}
