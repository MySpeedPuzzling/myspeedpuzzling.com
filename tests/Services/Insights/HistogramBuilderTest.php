<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\HistogramBuilder;

final class HistogramBuilderTest extends TestCase
{
    public function testBinsAndOutliersMoreThanThirtyPercentOffYourUsual(): void
    {
        $results = [
            ...InsightsTestResults::series(20, 1.0, days: 60),
            InsightsTestResults::result(['seconds' => 4500, 'days_ago' => 30]),  // 1.5 - slow outlier
            InsightsTestResults::result(['seconds' => 1800, 'days_ago' => 31]),  // 0.6 - fast outlier
            InsightsTestResults::result(['seconds' => 3600, 'days_ago' => 32]),  // 1.2 - not an outlier
            InsightsTestResults::result(['seconds' => 9000, 'days_ago' => 33]),  // 3.0 - into the end bar
        ];

        $histogram = (new HistogramBuilder())->build(InsightsTestResults::rows($results));

        self::assertCount(24, $histogram->bins);
        self::assertSame(-60, $histogram->bins[0]->fromPercent);
        self::assertSame(60, $histogram->bins[23]->toPercent);
        self::assertSame(24, array_sum(array_map(static fn ($bin) => $bin->count, $histogram->bins)));
        self::assertSame(1, $histogram->bins[0]->count, '-200 % lands in the slowest bar');
        self::assertSame(1, $histogram->bins[2]->count, '-50 % is the -50 … -45 % bar');
        self::assertSame(20, $histogram->bins[12]->count, 'level with the median: the 0-5 % bar');
        self::assertSame(3, $histogram->slowerThanMedianCount);
        self::assertSame(2, $histogram->slowOutlierCount);
        self::assertSame(1, $histogram->fastOutlierCount);
        self::assertSame(9000, $histogram->slowOutliers[0]->seconds, 'slowest first');
        self::assertSame(1800, $histogram->fastOutliers[0]->seconds);
        self::assertSame(0.0, $histogram->usualPercent);
        self::assertFalse($histogram->notSureYet);
    }
}
