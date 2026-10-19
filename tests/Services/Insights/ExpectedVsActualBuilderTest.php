<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\ExpectedVsActualBuilder;
use SpeedPuzzling\Web\Value\TimePredictionMethod;

final class ExpectedVsActualBuilderTest extends TestCase
{
    public function testOlderPredictionsGetYourMarginBuiltIn(): void
    {
        $results = [];

        // You beat the old statistical predictions by 10 % as a rule
        for ($i = 0; $i < 8; $i++) {
            $results[] = InsightsTestResults::predicted(2700, 3000, 2, ['days_ago' => 50 + $i]);
        }

        $results[] = InsightsTestResults::predicted(2000, 3000, 2, ['days_ago' => 40]); // far better than 2700
        $results[] = InsightsTestResults::predicted(3500, 3000, 3, ['days_ago' => 30]); // calibrated: 3000 is expected

        $section = (new ExpectedVsActualBuilder())->build(InsightsTestResults::rows($results));

        self::assertSame(10, $section->sampleSize);
        self::assertSame(1, $section->calibratedCount);
        self::assertEqualsWithDelta(0.9, $section->calibration[TimePredictionMethod::Statistical->value], 1e-12);
        self::assertEqualsWithDelta(0.1, $section->firstTryMargin, 1e-12);
        self::assertSame(1, $section->betterCount);
        self::assertSame(2000, $section->better[0]->seconds);
        self::assertSame(2700, $section->better[0]->expectedSeconds);
        self::assertSame(1, $section->worseCount);
        self::assertSame(3000, $section->worse[0]->expectedSeconds);
        self::assertSame(8, $section->withinCount);
    }

    public function testFewOlderPredictionsAreTakenAsTheyAre(): void
    {
        $results = [
            InsightsTestResults::predicted(2700, 3000, 2, ['days_ago' => 50]),
            InsightsTestResults::predicted(2700, 3000, 2, ['days_ago' => 51]),
            InsightsTestResults::result(['days_ago' => 52]),
        ];

        $section = (new ExpectedVsActualBuilder())->build(InsightsTestResults::rows($results));

        self::assertSame(2, $section->sampleSize);
        self::assertSame(1.0, $section->calibration[TimePredictionMethod::Statistical->value]);
        self::assertNull($section->firstTryMargin);
        self::assertTrue($section->notSureYet);
    }
}
