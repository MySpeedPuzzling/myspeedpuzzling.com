<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\WindowScaling;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\Wilson;

/**
 * Re-fitting the width of stored windows (WindowScaling) and the Wilson interval the honesty rule judges by - pure.
 */
final class WindowScalingTest extends TestCase
{
    public function testTheScalesAtWhichAWindowHoldsASolve(): void
    {
        // A window 1000 × e^±0.2 holds 1100 (y = ln 1.1 = 0.0953) from the scale 0.0953 / 0.2 on, however wide
        [$from, $to] = WindowScaling::scaleInterval(1100, 1000, (int) round(1000 * exp(-0.2)), (int) round(1000 * exp(0.2)));
        self::assertEqualsWithDelta(log(1.1) / log(1221 / 1000), $from, 1e-9);
        self::assertSame(INF, $to);

        // The actual time on the centre: every scale holds it
        self::assertSame([0.0, INF], WindowScaling::scaleInterval(1000, 1000, 900, 1100));

        // A window off its centre (a biased quantile bucket: both ends below the centre) holds the solve only between two scales
        $interval = WindowScaling::scaleInterval(800, 1000, 500, 900);
        self::assertNotNull($interval);
        self::assertEqualsWithDelta(log(0.8) / log(0.5), $interval[0], 1e-9);
        self::assertEqualsWithDelta(log(0.8) / log(0.9), $interval[1], 1e-9);

        // ... and never a solve on the other side of the centre
        self::assertNull(WindowScaling::scaleInterval(1100, 1000, 500, 900));
    }

    public function testAtScaleOneTheHitIsTheStoredWindowsOwn(): void
    {
        foreach ([[1000, 900, 1100, true], [1101, 900, 1100, false], [899, 900, 1100, false], [950, 900, 1100, true]] as [$actual, $low, $high, $inside]) {
            $interval = WindowScaling::scaleInterval($actual, 1000, $low, $high);
            $hit = $interval !== null && $interval[0] <= 1.0 + 1e-12 && 1.0 <= $interval[1] + 1e-12;
            self::assertSame($inside, $hit, "{$actual} in {$low}-{$high}");
        }
    }

    public function testTheFittedScaleIsTheSmallestReachingTheTarget(): void
    {
        // Ten solves held from 0.1, 0.2, … 1.0 on; one never held (counted, never reached)
        $from = [0.5, 0.1, 0.9, 0.3, 0.7, 0.2, 1.0, 0.6, 0.4, 0.8];
        $to = array_fill(0, 10, INF);

        self::assertEqualsWithDelta(0.5, WindowScaling::fitScale($from, $to, 10, 0.5), 1e-12);
        self::assertEqualsWithDelta(0.8, WindowScaling::fitScale($from, $to, 10, 0.8), 1e-12);
        self::assertEqualsWithDelta(0.9, WindowScaling::fitScale($from, $to, 11, 0.8), 1e-12, 'A solve no scale holds still counts');
        self::assertNull(WindowScaling::fitScale($from, $to, 20, 0.8), 'The target out of reach');
        self::assertNull(WindowScaling::fitScale([], [], 0, 0.5));

        self::assertSame(5, WindowScaling::hitsAt($from, $to, 0.5));
        // A bounded interval drops out again above its end
        self::assertSame(1, WindowScaling::hitsAt([0.2, 0.2], [0.4, INF], 0.5));
    }

    public function testWilsonBounds(): void
    {
        $bounds = Wilson::bounds(50, 100);
        self::assertNotNull($bounds);
        // z = 1.645 (90 %): 0.5 ± ~0.081
        self::assertEqualsWithDelta(0.4190, $bounds[0], 1e-3);
        self::assertEqualsWithDelta(0.5810, $bounds[1], 1e-3);

        // Never outside 0-1, and wide for few solves
        $none = Wilson::bounds(0, 5);
        self::assertNotNull($none);
        self::assertSame(0.0, $none[0]);
        self::assertGreaterThan(0.3, $none[1]);
        self::assertNull(Wilson::bounds(0, 0));
    }
}
