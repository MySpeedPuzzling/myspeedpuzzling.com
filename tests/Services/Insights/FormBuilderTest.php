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
}
