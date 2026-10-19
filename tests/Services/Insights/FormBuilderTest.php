<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Insights;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\Insights\FormBuilder;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;

final class FormBuilderTest extends TestCase
{
    public function testMonthlyMediansOverThePeriod(): void
    {
        $now = InsightsTestResults::now();
        $results = [];

        // Two years of results getting faster: 1.2 → 0.8 of the puzzle median
        for ($day = 720; $day >= 0; $day -= 6) {
            $results[] = InsightsTestResults::result(['seconds' => (int) round(3000 * (0.8 + 0.4 * $day / 720)), 'days_ago' => $day]);
        }

        $rows = InsightsTestResults::rows($results);
        $free = InsightsCriteria::fromUserInput([], isMember: false);
        $form = (new FormBuilder())->build($rows, $free->periodFrom($now), $free->periodBefore(), $now);

        // 6 months back from mid-October: April … October
        self::assertSame('2026-04', $form->months[0]->month);
        self::assertSame('2026-10', $form->months[count($form->months) - 1]->month);
        self::assertFalse($form->notSureYet);
        self::assertNotNull($form->change);
        self::assertLessThan(0, $form->change, 'faster at the end');
        self::assertNotNull($form->months[0]->usual);
        self::assertNotNull($form->months[0]->bestTimeId);
    }

    public function testAShortHistoryStillShowsFourMonths(): void
    {
        $now = InsightsTestResults::now();
        $rows = InsightsTestResults::rows(InsightsTestResults::series(4, 1.0, days: 10));
        $member = InsightsCriteria::fromUserInput([], isMember: true);

        $form = (new FormBuilder())->build($rows, $member->periodFrom($now), $member->periodBefore(), $now);

        self::assertCount(4, $form->months);
        self::assertSame('2026-10', $form->months[3]->month);
        self::assertTrue($form->notSureYet, 'one month with a median');
    }

    public function testTheChartNeverRunsIntoTheFuture(): void
    {
        $now = InsightsTestResults::now();
        $rows = InsightsTestResults::rows(InsightsTestResults::series(30, 1.0, days: 200));

        foreach (
            [
            ['period' => 'custom', 'from' => '2026-06-01', 'to' => '2100-12-31'],
            ['period' => 'past_year', 'year' => '2026'],
            ] as $query
        ) {
            $criteria = InsightsCriteria::fromUserInput($query, isMember: true);
            $form = (new FormBuilder())->build($rows, $criteria->periodFrom($now), $criteria->periodBefore(), $now);

            self::assertSame('2026-10', $form->months[count($form->months) - 1]->month, (string) json_encode($query));
        }
    }

    public function testOnlyThePeriodsOwnResultsCount(): void
    {
        $now = InsightsTestResults::now();
        // Three results on 1-3 June, three on 20-22 June: a period from 15 June counts only the later ones
        $results = [];

        foreach ([136, 135, 134, 117, 116, 115] as $daysAgo) {
            $results[] = InsightsTestResults::result(['days_ago' => $daysAgo, 'seconds' => $daysAgo > 120 ? 6000 : 3000]);
        }

        $criteria = InsightsCriteria::fromUserInput(['period' => 'custom', 'from' => '2026-06-15'], isMember: true);
        $form = (new FormBuilder())->build(InsightsTestResults::rows($results), $criteria->periodFrom($now), $criteria->periodBefore(), $now);

        self::assertSame('2026-06', $form->months[0]->month);
        self::assertSame(3, $form->months[0]->count);
        self::assertSame(1.0, $form->months[0]->medianRatio);
        self::assertNotNull($form->months[0]->usual, 'the results\' own usual');
    }
}
