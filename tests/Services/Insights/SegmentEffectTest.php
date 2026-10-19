<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\SegmentEffect;
use SpeedPuzzling\Web\Value\InsightsVerdict;

final class SegmentEffectTest extends TestCase
{
    public function testTooFewResultsSayNothing(): void
    {
        $stats = SegmentEffect::of([0.5, 0.5, 0.5, 0.5]);

        self::assertSame(4, $stats->sampleSize);
        self::assertNull($stats->effect);
        self::assertSame(InsightsVerdict::NotSureYet, $stats->verdict);
    }

    public function testFewerThanTenResultsAreNeverAStrength(): void
    {
        $stats = SegmentEffect::of(array_fill(0, 9, 0.8));

        self::assertNotNull($stats->effect);
        self::assertSame(InsightsVerdict::NotSureYet, $stats->verdict);
    }

    public function testTheMedianIsShrunkTowardYourUsual(): void
    {
        $stats = SegmentEffect::of(array_fill(0, 10, 0.8));

        // n / (n + 8) = 10 / 18 of the log effect
        self::assertEqualsWithDelta(0.8 ** (10 / 18) - 1, $stats->effect, 1e-12);
        self::assertSame(InsightsVerdict::Strength, $stats->verdict);
    }

    public function testAWeakSpotNeedsTheWholeRangeBeyondTheThreshold(): void
    {
        self::assertSame(InsightsVerdict::WeakSpot, SegmentEffect::of(array_fill(0, 20, 1.1))->verdict);

        // Half 0.9, half 1.12: the median is slower, but the range reaches below +1.5 %
        $mixed = [...array_fill(0, 10, 0.9), ...array_fill(0, 11, 1.12)];
        $stats = SegmentEffect::of($mixed);

        self::assertGreaterThan(0, $stats->effect);
        self::assertLessThan(0.015, $stats->low);
        self::assertSame(InsightsVerdict::AboutUsual, $stats->verdict);
    }

    public function testTheRangeIsTheOrderStatisticsOfTheMedian(): void
    {
        $residuals = [];

        for ($i = 1; $i <= 25; $i++) {
            $residuals[] = 0.8 + $i * 0.01;
        }

        shuffle($residuals);
        $stats = SegmentEffect::of($residuals);
        $shrink = 25 / 33;

        // 1.96 · √25 = 9.8 → ranks floor(15.2 / 2) = 7 and ceil(1 + 34.8 / 2) = 19, i.e. 0-based 6 and 18 around 12
        self::assertEqualsWithDelta(exp(log(0.87) * $shrink) - 1, $stats->low, 1e-12);
        self::assertEqualsWithDelta(exp(log(0.99) * $shrink) - 1, $stats->high, 1e-12);
        self::assertEqualsWithDelta(exp(log(0.93) * $shrink) - 1, $stats->effect, 1e-12);
    }

    public function testTheRangeIsSymmetric(): void
    {
        // n = 10: the range spans every result (ranks 1 … 10), so one result at your usual keeps both sides honest
        $faster = SegmentEffect::of([...array_fill(0, 9, 0.90), 1.0]);
        $slower = SegmentEffect::of([...array_fill(0, 9, 1.10), 1.0]);

        self::assertSame(InsightsVerdict::AboutUsual, $faster->verdict);
        self::assertSame(InsightsVerdict::AboutUsual, $slower->verdict);
        self::assertSame(0.0, $faster->high);
        self::assertSame(0.0, $slower->low);

        // Twenty: the range no longer reaches the one result at your usual - a strength exactly when its mirror is a weak spot
        $fasterTwenty = SegmentEffect::of([...array_fill(0, 19, 0.90), 1.0]);
        $slowerTwenty = SegmentEffect::of([...array_fill(0, 19, 1.10), 1.0]);

        self::assertSame(InsightsVerdict::Strength, $fasterTwenty->verdict);
        self::assertSame(InsightsVerdict::WeakSpot, $slowerTwenty->verdict);
    }
}
