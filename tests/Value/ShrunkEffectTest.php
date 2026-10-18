<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\ShrunkEffect;

final class ShrunkEffectTest extends TestCase
{
    public function testNothingBelowFiveRatios(): void
    {
        self::assertNull(ShrunkEffect::of([0.8, 0.8, 0.8, 0.8]));
    }

    public function testTheMedianIsShrunkTowardAsExpectedWithAnOrderStatisticRange(): void
    {
        $ratios = array_fill(0, 12, 0.9);
        $effect = ShrunkEffect::of($ratios);

        self::assertNotNull($effect);
        self::assertSame(12, $effect->sampleSize);
        // shrunk by 12 / 20
        self::assertEqualsWithDelta(exp(log(0.9) * 0.6) - 1.0, $effect->effect, 1e-12);
        self::assertEqualsWithDelta($effect->effect, $effect->low, 1e-12);
        self::assertEqualsWithDelta($effect->effect, $effect->high, 1e-12);
        self::assertTrue($effect->isFaster());
        self::assertFalse($effect->isSlower());
    }

    public function testAWideRangeSaysNeither(): void
    {
        $effect = ShrunkEffect::of([0.7, 0.85, 0.95, 1.0, 1.05, 1.2, 1.4]);

        self::assertNotNull($effect);
        self::assertLessThan(0.0, $effect->low);
        self::assertGreaterThan(0.0, $effect->high);
        self::assertFalse($effect->isFaster());
        self::assertFalse($effect->isSlower());
    }

    public function testSlowerTogether(): void
    {
        $effect = ShrunkEffect::of([1.2, 1.25, 1.3, 1.22, 1.28, 1.24, 1.26, 1.3, 1.21, 1.27]);

        self::assertNotNull($effect);
        self::assertTrue($effect->isSlower());
    }
}
