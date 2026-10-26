<?php

declare(strict_types=1);

namespace SystemClockRuleFixture;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\DatePoint;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/*
 * Fixture of SystemClockRuleTest: a line ending in a "reported" marker comment must be reported with the text after it, every other
 * line must not be reported.
 */

final class LaterMoment extends DateTimeImmutable
{
}

final class Reads
{
    private const string AGE = '-3 days';
    private const int DAYS = 7;

    public function reported(DateTimeZone $zone): void
    {
        new DateTimeImmutable(); // reported: new DateTimeImmutable()
        new \DateTime(); // reported: new DateTime()
        new DateTimeImmutable('now'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('NOW', $zone); // reported: new DateTimeImmutable()
        new DateTimeImmutable(timezone: $zone); // reported: new DateTimeImmutable()
        new DateTimeImmutable('today'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('tomorrow'); // reported: new DateTimeImmutable()
        new DateTime('yesterday'); // reported: new DateTime()
        new DateTimeImmutable('midnight'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('noon'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('+7 days'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('-1 hour'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('+' . self::DAYS . ' days'); // reported: new DateTimeImmutable()
        new DateTimeImmutable(self::AGE); // reported: new DateTimeImmutable()
        new DateTimeImmutable('next monday'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('last friday'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('first day of this month'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('last day of next month'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('12:00'); // reported: new DateTimeImmutable()
        new DateTimeImmutable('october 14'); // reported: new DateTimeImmutable()
        new DateTimeImmutable(''); // reported: new DateTimeImmutable()
        new LaterMoment(); // reported: new SystemClockRuleFixture\LaterMoment()
        new DatePoint(); // reported: new Symfony\Component\Clock\DatePoint()
        new DatePoint('-1 day'); // reported: new Symfony\Component\Clock\DatePoint()
        new MockClock(); // reported: new Symfony\Component\Clock\MockClock()
        new MockClock('-1 day'); // reported: new Symfony\Component\Clock\MockClock()
        new NativeClock(); // reported: new Symfony\Component\Clock\NativeClock()
        Clock::get(); // reported: Clock::get()
        time(); // reported: time()
        \time(); // reported: time()
        date('Y'); // reported: date()
        gmdate('Y-m-d'); // reported: gmdate()
        idate('Y'); // reported: idate()
        getdate(); // reported: getdate()
        localtime(); // reported: localtime()
        mktime(); // reported: mktime()
        mktime(0, 0, 0); // reported: mktime()
        gmmktime(); // reported: gmmktime()
        gettimeofday(); // reported: gettimeofday()
        strtotime('+1 day'); // reported: strtotime()
        strtotime('today'); // reported: strtotime()
        strtotime((string) $zone->getName()); // reported: strtotime()
        date_create(); // reported: date_create()
        date_create_immutable(); // reported: date_create_immutable()
        date_create_immutable('-2 weeks'); // reported: date_create_immutable()
    }

    public function notReported(string $given, int $timestamp, DateTimeImmutable $moment, DateTimeZone $zone): void
    {
        new DateTimeImmutable('2026-10-14 12:00:00');
        new DateTimeImmutable('2026-10-14');
        new DateTimeImmutable('2026-10-14T12:00:00+02:00');
        new DateTimeImmutable('@1700000000');
        new DateTimeImmutable('@' . $timestamp);
        new DateTimeImmutable('first monday of october 2026');
        new DateTimeImmutable('2026-10-14', $zone);
        new DateTimeImmutable($given);
        new DateTime($given, $zone);
        new LaterMoment('2026-10-14 12:00:00');
        new DatePoint('2026-10-14 12:00:00');
        new MockClock('2026-10-14 12:00:00');
        new MockClock($moment);
        DateTimeImmutable::createFromFormat('Y-m-d', '2026-10-14');
        $moment->modify('-3 days');
        date('Y', $timestamp);
        gmdate('Y-m-d', $timestamp);
        getdate($timestamp);
        localtime($timestamp);
        mktime(0, 0, 0, 10, 14, 2026);
        strtotime('2026-10-14 12:00:00');
        strtotime('+1 day', $timestamp);
        date_create('2026-10-14');
        date_create_immutable($given);
        microtime(true);
        hrtime(true);
        $read = time(...);
        $read();
    }
}
