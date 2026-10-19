<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Insights;

use SpeedPuzzling\Web\Results\Insights\InsightsSegmentStats;
use SpeedPuzzling\Web\Value\InsightsVerdict;
use SpeedPuzzling\Web\Value\ShrunkEffect;

/**
 * How a group of results compares with your usual (README §6 "Show how sure we are") - Value\ShrunkEffect (the one
 * rule, shared with the pairs & teams synergy of stream 4c) on the residuals: the median of the log residuals shrunk
 * toward your usual by n / (n + 8), with the distribution-free order-statistic range of that median (1-based ranks
 * floor((n − 1.96·√n) / 2) and ceil(1 + (n + 1.96·√n) / 2), symmetric around it; never a bootstrap), shrunk the same
 * way. Shown from MIN_SHOWN results; a strength or weak spot only from MIN_VERDICT results on and only when the whole
 * range lies beyond ±ShrunkEffect::THRESHOLD.
 */
final class SegmentEffect
{
    public const int MIN_SHOWN = ShrunkEffect::MINIMUM_SAMPLE;

    public const int MIN_VERDICT = 10;

    /**
     * @param list<float> $residuals 1.0 = your usual
     */
    public static function of(array $residuals): InsightsSegmentStats
    {
        $effect = ShrunkEffect::of($residuals);

        if ($effect === null) {
            return new InsightsSegmentStats(count($residuals), null, null, null, InsightsVerdict::NotSureYet);
        }

        $verdict = match (true) {
            $effect->sampleSize < self::MIN_VERDICT => InsightsVerdict::NotSureYet,
            $effect->isFaster() => InsightsVerdict::Strength,
            $effect->isSlower() => InsightsVerdict::WeakSpot,
            default => InsightsVerdict::AboutUsual,
        };

        return new InsightsSegmentStats($effect->sampleSize, $effect->effect, $effect->low, $effect->high, $verdict);
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
