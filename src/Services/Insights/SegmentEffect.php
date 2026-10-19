<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsSegmentStats;
use SpeedPuzzling\Web\Value\InsightsVerdict;

/**
 * How a group of results compares with your usual (README §6 "Show how sure we are"): the median of the log
 * residuals, shrunk toward your usual by n / (n + 8), with the distribution-free order-statistic range of that median
 * - 1-based ranks floor((n − 1.96·√n) / 2) and ceil(1 + (n + 1.96·√n) / 2), symmetric around the median; never a
 * bootstrap: same answer every time, O(n log n) - shrunk the same way. A strength or weak spot only from MIN_VERDICT
 * results on and only when the whole range lies beyond ±THRESHOLD.
 */
final class SegmentEffect
{
    public const int MIN_SHOWN = 5;

    public const int MIN_VERDICT = 10;

    public const int SHRINKAGE = 8;

    public const float THRESHOLD = 0.015;

    /** z of the 95 % range */
    private const float Z = 1.96;

    /**
     * @param list<float> $residuals 1.0 = your usual
     */
    public static function of(array $residuals): InsightsSegmentStats
    {
        $count = count($residuals);

        if ($count < self::MIN_SHOWN) {
            return new InsightsSegmentStats($count, null, null, null, InsightsVerdict::NotSureYet);
        }

        $logs = array_map(static fn (float $residual): float => log($residual), $residuals);
        sort($logs);

        $shrink = $count / ($count + self::SHRINKAGE);

        // 1-based ranks of the order statistics bounding the median at 95 %, symmetric around it (n = 10: 1 … 10,
        // n = 25: 7 … 19) - the same rule as Value\ShrunkEffect (stream 4c)
        $spread = self::Z * sqrt($count);
        $lowIndex = max(1, (int) floor(($count - $spread) / 2)) - 1;
        $highIndex = min($count, (int) ceil(1 + ($count + $spread) / 2)) - 1;

        $effect = exp(InsightsMath::medianOfSorted($logs) * $shrink) - 1;
        $low = exp($logs[$lowIndex] * $shrink) - 1;
        $high = exp($logs[$highIndex] * $shrink) - 1;

        $verdict = match (true) {
            $count < self::MIN_VERDICT => InsightsVerdict::NotSureYet,
            $high < -self::THRESHOLD => InsightsVerdict::Strength,
            $low > self::THRESHOLD => InsightsVerdict::WeakSpot,
            default => InsightsVerdict::AboutUsual,
        };

        return new InsightsSegmentStats($count, $effect, $low, $high, $verdict);
    }

    /**
     * @param list<InsightsRow> $rows
     */
    public static function ofRows(array $rows): InsightsSegmentStats
    {
        $residuals = [];

        foreach ($rows as $row) {
            if ($row->residual !== null) {
                $residuals[] = $row->residual;
            }
        }

        return self::of($residuals);
    }
}
