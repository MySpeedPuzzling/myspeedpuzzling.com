<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use Symfony\Component\Clock\MockClock;

final class ActivityCalendarStreakCalculatorTest extends TestCase
{
    public function testEmptyInput(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate([]);

        self::assertSame(0, $streak->current);
        self::assertSame(0, $streak->longest);
        self::assertSame([], $streak->currentStreakDates);
        self::assertSame([], $streak->longestStreakDates);
    }

    public function testSingleDayToday(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-16']);

        self::assertSame(1, $streak->current);
        self::assertSame(1, $streak->longest);
        self::assertSame(['2026-04-16'], $streak->currentStreakDates);
        self::assertSame(['2026-04-16'], $streak->longestStreakDates);
    }

    public function testThreeConsecutiveDaysEndingToday(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-14', '2026-04-15', '2026-04-16']);

        self::assertSame(3, $streak->current);
        self::assertSame(3, $streak->longest);
        self::assertSame(['2026-04-14', '2026-04-15', '2026-04-16'], $streak->currentStreakDates);
        self::assertSame(['2026-04-14', '2026-04-15', '2026-04-16'], $streak->longestStreakDates);
    }

    public function testThreeConsecutiveDaysEndingYesterdayStillCountsAsCurrent(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-13', '2026-04-14', '2026-04-15']);

        self::assertSame(3, $streak->current);
        self::assertSame(3, $streak->longest);
        self::assertSame(['2026-04-13', '2026-04-14', '2026-04-15'], $streak->currentStreakDates);
        self::assertSame(['2026-04-13', '2026-04-14', '2026-04-15'], $streak->longestStreakDates);
    }

    public function testStreakEndingTwoDaysAgoIsNotCurrent(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-12', '2026-04-13', '2026-04-14']);

        self::assertSame(0, $streak->current);
        self::assertSame(3, $streak->longest);
        self::assertSame([], $streak->currentStreakDates);
        self::assertSame(['2026-04-12', '2026-04-13', '2026-04-14'], $streak->longestStreakDates);
    }

    public function testLongestIsGreaterThanCurrent(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate([
            '2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05', // 5-day run
            '2026-04-15', '2026-04-16', // 2-day current run
        ]);

        self::assertSame(2, $streak->current);
        self::assertSame(5, $streak->longest);
        self::assertSame(['2026-04-15', '2026-04-16'], $streak->currentStreakDates);
        self::assertSame(
            ['2026-03-01', '2026-03-02', '2026-03-03', '2026-03-04', '2026-03-05'],
            $streak->longestStreakDates,
        );
    }

    public function testMultipleRunsTiedForLongestAreAllIncluded(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate([
            '2026-04-01', '2026-04-02', '2026-04-03', // 3-day run
            '2026-04-10', '2026-04-11', '2026-04-12', // 3-day run
        ]);

        self::assertSame(0, $streak->current);
        self::assertSame(3, $streak->longest);
        self::assertSame(
            ['2026-04-01', '2026-04-02', '2026-04-03', '2026-04-10', '2026-04-11', '2026-04-12'],
            $streak->longestStreakDates,
        );
    }

    public function testDuplicateDatesAreCollapsed(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-16', '2026-04-16', '2026-04-15', '2026-04-15']);

        self::assertSame(2, $streak->current);
        self::assertSame(2, $streak->longest);
    }

    public function testUnsortedInputIsHandled(): void
    {
        $calculator = new ActivityCalendarStreakCalculator(new MockClock('2026-04-16 12:00:00'));

        $streak = $calculator->calculate(['2026-04-16', '2026-04-14', '2026-04-15']);

        self::assertSame(3, $streak->current);
        self::assertSame(3, $streak->longest);
    }
}
