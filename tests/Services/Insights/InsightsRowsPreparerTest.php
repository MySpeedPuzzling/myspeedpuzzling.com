<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\InsightsRowsPreparer;
use SpeedPuzzling\Web\Services\Insights\LearningFactors;
use SpeedPuzzling\Web\Value\InsightsSituation;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;

final class InsightsRowsPreparerTest extends TestCase
{
    public function testYourUsualIsTheRollingMedianOfYourBoxedFirstTries(): void
    {
        $results = [
            InsightsTestResults::result(['seconds' => 2700, 'days_ago' => 200]),          // ratio 0.9
            InsightsTestResults::result(['seconds' => 3300, 'days_ago' => 190]),          // 1.1
            InsightsTestResults::result(['seconds' => 1500, 'days_ago' => 185, 'unboxed' => true]), // 0.5 - not in the usual
            InsightsTestResults::result(['seconds' => 2400, 'days_ago' => 180, 'firstAttempt' => false, 'attempt' => 2]), // 0.8 - a repeat
            InsightsTestResults::result(['seconds' => 3000, 'days_ago' => 20]),           // 1.0, more than 75 days after the rest
        ];

        $rows = InsightsTestResults::rows($results);

        self::assertEqualsWithDelta(1.0, $rows[0]->form, 1e-12);
        self::assertEqualsWithDelta(1.0, $rows[3]->form, 1e-12);
        self::assertEqualsWithDelta(0.8, $rows[3]->residual, 1e-12);
        self::assertEqualsWithDelta(0.5, $rows[2]->residual, 1e-12);
        self::assertEqualsWithDelta(1.0, $rows[4]->form, 1e-12, 'only its own first try is within ±75 days');
    }

    public function testTheResidualTakesTheCommunityLearningOut(): void
    {
        $factors = LearningFactors::fromGlobalRatios([500 => [1 => ['ratio' => 0.9, 'sample_size' => 100]]]);
        $results = [
            InsightsTestResults::result(['seconds' => 3000, 'days_ago' => 30]),
            InsightsTestResults::result(['seconds' => 2700, 'days_ago' => 20, 'firstAttempt' => false, 'attempt' => 2]),
        ];

        $rows = (new InsightsRowsPreparer())->prepare($results, $factors, null);

        // 0.9 ratio on a second attempt = exactly what everybody gains: your usual
        self::assertEqualsWithDelta(1.0, $rows[1]->residual, 1e-12);
    }

    public function testSolveDaysBreaksAndTheTimeYouLogged(): void
    {
        $sameDay = InsightsTestResults::result(['days_ago' => 5]);
        $prague = new DateTimeZone('Europe/Prague');

        $results = [
            InsightsTestResults::result(['days_ago' => 40]),
            InsightsTestResults::result(['days_ago' => 5]),
            $sameDay,
            // Logged a day after the solve: no time of day
            InsightsTestResults::result(['days_ago' => 2, 'trackedAt' => InsightsTestResults::now()->setTime(9, 0)->modify('-1 day')]),
        ];

        $rows = InsightsTestResults::rows($results, $prague);

        self::assertNull($rows[0]->daysSincePrevious);
        self::assertSame(35, $rows[1]->daysSincePrevious);
        self::assertContains(InsightsSituation::AfterBreak, $rows[1]->situations());
        self::assertSame(0, $rows[2]->daysSincePrevious);
        self::assertSame(1, $rows[2]->sameDayIndex);
        self::assertContains(InsightsSituation::SecondOfTheDay, $rows[2]->situations());

        // 18:30 UTC is 20:30 in Prague (summer time)
        self::assertSame(InsightsTimeOfDay::Evening, $rows[1]->timeOfDay);
        self::assertNull($rows[3]->timeOfDay);
        self::assertSame((int) $results[1]->solvedAt->format('N'), $rows[1]->weekday);

        $withoutZone = InsightsTestResults::rows($results);
        self::assertNull($withoutZone[1]->timeOfDay);
    }
}
