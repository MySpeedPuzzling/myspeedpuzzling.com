<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\PredictionAccuracyBuilder;

final class PredictionAccuracyBuilderTest extends TestCase
{
    public function testOnlyCalibratedWindowsCount(): void
    {
        $older = [];

        for ($i = 0; $i < 15; $i++) {
            $older[] = InsightsTestResults::predicted(3000, 3000, 2, ['days_ago' => 100 + $i]);
        }

        $section = (new PredictionAccuracyBuilder())->build(InsightsTestResults::rows($older));

        self::assertTrue($section->notSureYet);
        self::assertSame(0, $section->sampleSize);
        self::assertSame(15, $section->olderPredictionCount);
        self::assertNull($section->hitRate);
    }

    public function testTheHitRateOfYourLikelyWindows(): void
    {
        $results = [];

        foreach ([3000, 3000, 2950, 3050, 3000, 3000, 2000, 2100, 2200, 4000, 4100, 4200] as $i => $seconds) {
            $results[] = InsightsTestResults::predicted($seconds, 3000, 3, ['days_ago' => 20 + $i, 'firstAttempt' => $i % 2 === 0], low: 2900, high: 3100);
        }

        $section = (new PredictionAccuracyBuilder())->build(InsightsTestResults::rows($results));

        self::assertFalse($section->notSureYet);
        self::assertSame(12, $section->sampleSize);
        self::assertSame(6, $section->hits);
        self::assertSame(0.5, $section->hitRate);
        self::assertSame(3, $section->fasterThanWindow);
        self::assertSame(3, $section->slowerThanWindow);
        self::assertSame(6, $section->firstTrySampleSize);
        self::assertSame(0.0, $section->medianMargin);
    }
}
