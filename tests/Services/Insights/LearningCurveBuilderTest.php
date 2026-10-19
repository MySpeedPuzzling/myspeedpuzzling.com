<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\LearningCurveBuilder;
use SpeedPuzzling\Web\Services\Insights\LearningFactors;

final class LearningCurveBuilderTest extends TestCase
{
    public function testYourCurveTheCommunitysAndTheSelection(): void
    {
        $puzzle = '00000000-0000-7000-9000-aaaaaaaaaaaa';
        $results = [
            ...InsightsTestResults::series(10, 1.0, days: 200),
            InsightsTestResults::result(['puzzleId' => $puzzle, 'seconds' => 3000, 'days_ago' => 150]),
            InsightsTestResults::result(['puzzleId' => $puzzle, 'seconds' => 2550, 'days_ago' => 120, 'firstAttempt' => false, 'attempt' => 2]),
            InsightsTestResults::result(['puzzleId' => $puzzle, 'seconds' => 2400, 'days_ago' => 100, 'firstAttempt' => false, 'attempt' => 3]),
        ];
        $community = LearningFactors::fromGlobalRatios([
            500 => [1 => ['ratio' => 0.88, 'sample_size' => 500], 2 => ['ratio' => 0.95, 'sample_size' => 10]],
        ]);

        $section = (new LearningCurveBuilder())->build(
            InsightsTestResults::rows($results),
            [1 => ['ratio' => 0.85, 'sample_size' => 12], 2 => ['ratio' => 0.95, 'sample_size' => 4]],
            $community,
            500,
        );

        self::assertSame(1.0, $section->you[0]->factor);
        self::assertEqualsWithDelta(0.85, $section->you[1]->factor, 1e-12);
        self::assertSame(12, $section->you[1]->sampleSize);
        self::assertEqualsWithDelta(0.8075, $section->you[2]->factor, 1e-12);
        self::assertNull($section->you[3]->factor);

        // The 2 → 3 transition rests on 10 < 30 transitions: no 500-piece fallback beyond itself, so 1.0
        self::assertEqualsWithDelta(0.88, $section->community[1]->factor, 1e-12);
        self::assertEqualsWithDelta(0.88, $section->community[2]->factor, 1e-12);

        self::assertSame(1, $section->repeatedPuzzleCount);
        self::assertSame($puzzle, $section->learnedMost[0]->puzzleId);
        self::assertEqualsWithDelta(0.2, $section->learnedMost[0]->gain, 1e-12);
        self::assertNull($section->inSelection[1]->factor, 'one puzzle is not enough');
        self::assertFalse($section->notSureYet);
    }
}
