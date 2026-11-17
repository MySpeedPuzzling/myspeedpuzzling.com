<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\AnalyticsStudio;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioDistribution;

final class AnalyticsStudioDistributionTest extends TestCase
{
    public function testPercentilesInterpolateLikePostgresPercentileCont(): void
    {
        $bests = [100, 200, 300, 400, 500];

        self::assertSame(300, AnalyticsStudioDistribution::percentile($bests, 0.5));
        self::assertSame(140, AnalyticsStudioDistribution::percentile($bests, 0.1));
        self::assertSame(['p10' => 140, 'p25' => 200, 'p50' => 300, 'p75' => 400, 'p90' => 460], AnalyticsStudioDistribution::percentiles($bests));
        self::assertSame(250, AnalyticsStudioDistribution::percentile([200, 300], 0.5));
        self::assertSame(42, AnalyticsStudioDistribution::percentile([42], 0.9));
        self::assertNull(AnalyticsStudioDistribution::percentile([], 0.5));
        self::assertNull(AnalyticsStudioDistribution::percentiles([]));
    }

    public function testThePlaceIsOneMoreThanTheSolversStrictlyFaster(): void
    {
        $bests = [100, 200, 200, 300];

        self::assertSame(1, AnalyticsStudioDistribution::placeOf($bests, 50));
        self::assertSame(1, AnalyticsStudioDistribution::placeOf($bests, 100));
        self::assertSame(2, AnalyticsStudioDistribution::placeOf($bests, 200), 'a tie shares the place');
        self::assertSame(4, AnalyticsStudioDistribution::placeOf($bests, 250));
        self::assertSame(5, AnalyticsStudioDistribution::placeOf($bests, 301));
        self::assertSame(1, AnalyticsStudioDistribution::placeOf([], 301));
    }

    public function testTheHistogramCountsEverySolverOnce(): void
    {
        $bests = range(1000, 2999);
        $histogram = AnalyticsStudioDistribution::histogram($bests);

        self::assertCount(20, $histogram);
        self::assertSame(1000, $histogram[0]['fromSeconds']);
        self::assertSame(count($bests), array_sum(array_column($histogram, 'count')));

        $fromSeconds = array_column($histogram, 'fromSeconds');
        $sorted = $fromSeconds;
        sort($sorted);
        self::assertSame($sorted, $fromSeconds);
    }

    public function testFewDistinctTimesMakeFewerBuckets(): void
    {
        self::assertSame([['fromSeconds' => 600, 'count' => 3]], AnalyticsStudioDistribution::histogram([600, 600, 600]));
        self::assertSame([], AnalyticsStudioDistribution::histogram([]));

        $two = AnalyticsStudioDistribution::histogram([600, 601]);
        self::assertSame(2, array_sum(array_column($two, 'count')));
    }

    public function testTheSlowTailFallsIntoTheLastBucket(): void
    {
        $bests = [...range(1000, 1099), 50000];
        $histogram = AnalyticsStudioDistribution::histogram($bests);

        self::assertSame(101, array_sum(array_column($histogram, 'count')));
        self::assertLessThan(50000, $histogram[count($histogram) - 1]['fromSeconds']);
        self::assertGreaterThanOrEqual(1, $histogram[count($histogram) - 1]['count']);
    }
}
