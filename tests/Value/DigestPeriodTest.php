<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Exceptions\InvalidDigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;

final class DigestPeriodTest extends TestCase
{
    public function testAWeekIsAnIsoWeekInPragueTime(): void
    {
        $period = DigestPeriod::containing(DigestPeriodLength::Week, $this->prague('2026-10-14 15:00'));

        self::assertSame('2026-W42', $period->key);
        self::assertSame('2026-10-12 00:00', $period->start->format('Y-m-d H:i'));
        self::assertSame('2026-10-19 00:00', $period->end->format('Y-m-d H:i'));
        self::assertSame('2026-10-18', $period->lastDay()->format('Y-m-d'));
        self::assertSame('2026-10-19', $period->sendDay()->format('Y-m-d'));
        self::assertSame('2026-10-20', $period->noActivityDay()->format('Y-m-d'));
    }

    public function testSundayNightInUtcIsAlreadyMondayInPrague(): void
    {
        // Sunday 22:30 UTC = Monday 00:30 Prague (CEST): the next week
        $period = DigestPeriod::containing(DigestPeriodLength::Week, new DateTimeImmutable('2026-10-18 22:30:00', new DateTimeZone('UTC')));

        self::assertSame('2026-W43', $period->key);
    }

    public function testTheUtcBoundariesFollowTheDaylightSavingChange(): void
    {
        // Summer time ends on Sunday 25 October 2026: the week starts at UTC+2 and ends at UTC+1
        $period = DigestPeriod::fromKey('2026-W43');

        self::assertSame('2026-10-18 22:00:00', $period->startUtc());
        self::assertSame('2026-10-25 23:00:00', $period->endUtc());
        self::assertSame('2026-10-26 00:00', $period->end->format('Y-m-d H:i'));

        // Spring: 29 March 2026 moves to UTC+2
        $spring = DigestPeriod::fromKey('2026-W13');
        self::assertSame('2026-03-22 23:00:00', $spring->startUtc());
        self::assertSame('2026-03-29 22:00:00', $spring->endUtc());
    }

    public function testTheWeekAcrossTheNewYearBelongsToTheIsoYear(): void
    {
        $period = DigestPeriod::containing(DigestPeriodLength::Week, $this->prague('2027-01-01 12:00'));

        self::assertSame('2026-W53', $period->key);
        self::assertSame('2026-12-28', $period->start->format('Y-m-d'));
        self::assertSame('2027-01-04', $period->end->format('Y-m-d'));
        self::assertSame('2027-W01', $period->next()->key);
        self::assertSame('2026-W52', $period->previous()->key);
    }

    public function testTwoWeekPeriodsFollowTheFixedRhythmFromTheFirstPeriod(): void
    {
        $first = DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-10-12 00:00'));
        self::assertSame('2026-W42-W43', $first->key);
        self::assertSame('2026-10-26', $first->sendDay()->format('Y-m-d'));

        // The second week of a period belongs to the same period
        self::assertSame('2026-W42-W43', DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-10-25 23:59'))->key);
        self::assertSame('2026-W40-W41', $first->previous()->key);
        self::assertSame('2026-W44-W45', $first->next()->key);

        // 2026 has 53 ISO weeks: the rhythm continues into 2027 without a gap
        $yearEnd = DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-12-30 12:00'));
        self::assertSame('2026-W52-W53', $yearEnd->key);
        self::assertSame('2027-W01-W02', $yearEnd->next()->key);
    }

    public function testTwoWeekPeriodsTileTheCalendarForYears(): void
    {
        $period = DigestPeriod::fromKey('2026-W42-W43');
        $seen = [];
        $crossesTheYear = false;

        for ($i = 0; $i < 320; $i++) {
            $next = $period->next();

            self::assertSame('1', $period->start->format('N'));
            self::assertSame('00:00', $period->start->format('H:i'));
            self::assertEquals($period->end, $next->start, $period->key);
            self::assertSame($period->key, DigestPeriod::fromKey($period->key)->key);
            self::assertEquals($period, $next->previous());
            self::assertArrayNotHasKey($period->key, $seen);
            $seen[$period->key] = true;
            $crossesTheYear = $crossesTheYear || preg_match('/-W53-W01$/', $period->key) === 1;
            $period = $next;
        }

        // A 53-week year eventually puts its last week first in a period (2032)
        self::assertTrue($crossesTheYear);
        self::assertSame('2032-W53-W01', DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2033-01-05 12:00'))->key);
    }

    public function testTwoWeekPeriodsBeforeTheAnchorKeepTheRhythm(): void
    {
        self::assertSame('2026-W40-W41', DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-10-11 12:00'))->key);
        self::assertSame('2026-W40-W41', DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-09-28 00:00'))->key);
        self::assertSame('2026-W38-W39', DigestPeriod::containing(DigestPeriodLength::TwoWeeks, $this->prague('2026-09-27 23:59'))->key);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function provideMonths(): iterable
    {
        // month key => end, send day (the first Monday on or after the end)
        yield 'October 2026 ends on a Sunday' => ['2026-10', '2026-11-01', '2026-11-02'];
        yield 'November 2026 ends on a Tuesday' => ['2026-11', '2026-12-01', '2026-12-07'];
        yield 'December 2026 crosses the year' => ['2026-12', '2027-01-01', '2027-01-04'];
        yield 'February 2027 ends on a Monday' => ['2027-02', '2027-03-01', '2027-03-01'];
        yield 'February 2028 is a leap month' => ['2028-02', '2028-03-01', '2028-03-06'];
    }

    #[DataProvider('provideMonths')]
    public function testAMonthIsSentOnTheFirstMondayAfterIt(string $key, string $end, string $sendDay): void
    {
        $period = DigestPeriod::fromKey($key);

        self::assertSame($key, $period->key);
        self::assertSame('01', $period->start->format('d'));
        self::assertSame($end, $period->end->format('Y-m-d'));
        self::assertSame($sendDay, $period->sendDay()->format('Y-m-d'));
        self::assertSame('1', $period->sendDay()->format('N'));
    }

    /**
     * @return iterable<string, array{DigestPeriodLength, string, string, bool, bool}>
     */
    public static function provideRuns(): iterable
    {
        // length, run moment (Prague), period found, before the first period, stale
        yield 'weekly, the last Monday of the pause' => [DigestPeriodLength::Week, '2026-10-12 07:01', '2026-W41', true, false];
        yield 'weekly, the first send' => [DigestPeriodLength::Week, '2026-10-19 07:01', '2026-W42', false, false];
        yield 'weekly, the no-activity Tuesday' => [DigestPeriodLength::Week, '2026-10-20 07:01', '2026-W42', false, false];
        yield 'weekly, a re-run on Wednesday' => [DigestPeriodLength::Week, '2026-10-21 23:59', '2026-W42', false, false];
        yield 'weekly, Thursday is too late' => [DigestPeriodLength::Week, '2026-10-22 00:00', '2026-W42', false, true];
        yield 'two weeks, during the pause' => [DigestPeriodLength::TwoWeeks, '2026-10-19 07:01', '2026-W40-W41', true, true];
        yield 'two weeks, the first send' => [DigestPeriodLength::TwoWeeks, '2026-10-26 07:01', '2026-W42-W43', false, false];
        yield 'two weeks, an off Monday' => [DigestPeriodLength::TwoWeeks, '2026-11-02 07:01', '2026-W42-W43', false, true];
        yield 'two weeks, across the new year' => [DigestPeriodLength::TwoWeeks, '2027-01-04 07:01', '2026-W52-W53', false, false];
        yield 'month, October is paused' => [DigestPeriodLength::Month, '2026-11-02 07:01', '2026-10', true, false];
        yield 'month, a Monday that is not the first' => [DigestPeriodLength::Month, '2026-11-30 07:01', '2026-10', true, true];
        yield 'month, before its first Monday' => [DigestPeriodLength::Month, '2026-12-01 07:01', '2026-10', true, true];
        yield 'month, the first monthly send' => [DigestPeriodLength::Month, '2026-12-07 07:01', '2026-11', false, false];
        yield 'month, December on 4 January' => [DigestPeriodLength::Month, '2027-01-04 07:01', '2026-12', false, false];
    }

    #[DataProvider('provideRuns')]
    public function testARunFindsThePeriodWhoseSendDayHasCome(DigestPeriodLength $length, string $now, string $expectedKey, bool $beforeFirst, bool $stale): void
    {
        $nowMoment = $this->prague($now);
        $period = DigestPeriod::latestSentBy($length, $nowMoment);

        self::assertSame($expectedKey, $period->key);
        self::assertSame($beforeFirst, $period->isBeforeFirstPeriod());
        self::assertSame($stale, $period->isStaleAt($nowMoment));
    }

    public function testTheFirstPeriodIsTheWeekAfterTheLaunchNewsletter(): void
    {
        self::assertFalse(DigestPeriod::fromKey('2026-W42')->isBeforeFirstPeriod());
        self::assertTrue(DigestPeriod::fromKey('2026-W41')->isBeforeFirstPeriod());
        self::assertFalse(DigestPeriod::fromKey('2026-W42-W43')->isBeforeFirstPeriod());
        self::assertTrue(DigestPeriod::fromKey('2026-10')->isBeforeFirstPeriod());
        self::assertFalse(DigestPeriod::fromKey('2026-11')->isBeforeFirstPeriod());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideInvalidKeys(): iterable
    {
        yield 'week 54' => ['2026-W54'];
        yield 'week 00' => ['2026-W00'];
        yield 'week 53 of a 52-week year' => ['2027-W53'];
        yield 'two weeks off the rhythm' => ['2026-W43-W44'];
        yield 'two weeks not following each other' => ['2026-W42-W45'];
        yield 'month 13' => ['2026-13'];
        yield 'one-digit week' => ['2026-W4'];
        yield 'garbage' => ['last-week'];
        yield 'far past' => ['1970-01'];
    }

    #[DataProvider('provideInvalidKeys')]
    public function testOnlyCanonicalKeysAreAccepted(string $key): void
    {
        $this->expectException(InvalidDigestPeriod::class);

        DigestPeriod::fromKey($key);
    }

    private function prague(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('Europe/Prague'));
    }
}
