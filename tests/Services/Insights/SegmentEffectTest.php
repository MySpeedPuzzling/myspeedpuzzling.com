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

        // half = 1.96 · √25 / 2 = 4.9 → order statistics floor(12.5 − 4.9) = 7 and ceil(12.5 + 4.9) = 18 (0-based)
        self::assertEqualsWithDelta(exp(log(0.88) * $shrink) - 1, $stats->low, 1e-12);
        self::assertEqualsWithDelta(exp(log(0.99) * $shrink) - 1, $stats->high, 1e-12);
        self::assertEqualsWithDelta(exp(log(0.93) * $shrink) - 1, $stats->effect, 1e-12);
    }
}
