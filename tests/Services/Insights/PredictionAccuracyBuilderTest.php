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

    /**
     * The 80 % range counts only the calibrated predictions that stored one; a hit is judged against the range as
     * shown (rounded like on the recap)
     */
    public function testTheHitRateOfYourEightyPercentRange(): void
    {
        $results = [];

        foreach ([3000, 3100, 2800, 3300, 2700, 3200, 2900, 3000, 3400, 3500, 2000, 4500] as $i => $seconds) {
            $results[] = InsightsTestResults::predicted($seconds, 3000, 3, [
                'days_ago' => 20 + $i,
                'predictedRange80LowSeconds' => 2700,
                'predictedRange80HighSeconds' => 3420,
            ], low: 2900, high: 3100);
        }

        // An older model's prediction and a calibrated one without an 80 % range never count in it
        $results[] = InsightsTestResults::predicted(3000, 3000, 2, ['days_ago' => 50, 'predictedRange80LowSeconds' => 2700, 'predictedRange80HighSeconds' => 3420]);
        $results[] = InsightsTestResults::predicted(3000, 3000, 3, ['days_ago' => 51], low: 2900, high: 3100);

        $section = (new PredictionAccuracyBuilder())->build(InsightsTestResults::rows($results));

        self::assertSame(12, $section->range80SampleSize);
        // 2700-3420 shown as 45-57 min: 3500, 2000 and 4500 land outside
        self::assertSame(9, $section->range80Hits);
        self::assertSame(0.75, $section->range80HitRate);
        self::assertSame(13, $section->sampleSize, 'the 50 % window counts the one without an 80 % range too');
    }

    public function testTheEightyPercentRateWaitsForEnoughRanges(): void
    {
        $results = [];

        for ($i = 0; $i < 12; $i++) {
            $results[] = InsightsTestResults::predicted(3000, 3000, 3, ['days_ago' => 20 + $i] + ($i < 4 ? ['predictedRange80LowSeconds' => 2700, 'predictedRange80HighSeconds' => 3420] : []), low: 2900, high: 3100);
        }

        $section = (new PredictionAccuracyBuilder())->build(InsightsTestResults::rows($results));

        self::assertFalse($section->notSureYet);
        self::assertSame(4, $section->range80SampleSize);
        self::assertNull($section->range80HitRate);
    }
}
