<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\InsightsMath;

final class InsightsMathTest extends TestCase
{
    public function testRollingMedianEqualsTheNaiveWindowMedian(): void
    {
        mt_srand(4);
        $points = [];
        $moment = 0;

        for ($i = 0; $i < 400; $i++) {
            $moment += mt_rand(0, 5) * InsightsMath::DAY;
            $points[] = [$moment, mt_rand(600, 1600) / 1000];
        }

        $moments = [];

        for ($query = -10 * InsightsMath::DAY; $query <= $moment + 10 * InsightsMath::DAY; $query += 3 * InsightsMath::DAY) {
            $moments[] = $query;
        }

        $halfWindow = 75 * InsightsMath::DAY;
        $rolling = InsightsMath::rollingMedian($points, $moments, $halfWindow);

        foreach ($moments as $index => $query) {
            $window = [];

            foreach ($points as [$at, $value]) {
                if ($at >= $query - $halfWindow && $at <= $query + $halfWindow) {
                    $window[] = $value;
                }
            }

            $expected = $window === [] ? null : InsightsMath::median($window);
            self::assertSame($expected, $rolling[$index], "query #{$index}");
        }
    }

    public function testQuantileInterpolatesBetweenRanks(): void
    {
        self::assertEqualsWithDelta(1.75, InsightsMath::quantile([1.0, 2.0, 3.0], 0.375), 1e-12);
        self::assertSame(2.0, InsightsMath::quantile([3.0, 1.0, 2.0], 0.5));
        self::assertSame(2.5, InsightsMath::median([4.0, 1.0, 2.0, 3.0]));
    }
}
