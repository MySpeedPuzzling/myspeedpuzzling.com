<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;

/**
 * The dating rule of the events page (docs/features/events-page/README.md, "Dates"): rounds on separate days are
 * sessions, a long span without rounds is ongoing.
 */
final class OccurrenceDatesTest extends TestCase
{
    public function testMonthlyRoundsAreSeparateSessions(): void
    {
        $sessions = OccurrenceDates::sessions(true, self::day('2026-06-17'), self::day('2026-10-21'), [
            self::round('a', 'June 2026', '2026-06-17 23:00'),
            self::round('b', 'July 2026', '2026-07-15 23:00'),
            self::round('c', 'October 2026', '2026-10-21 23:00'),
        ]);

        self::assertSame(
            [['2026-06-17', null, 0, 'a', 'June 2026'], ['2026-07-15', null, 1, 'b', 'July 2026'], ['2026-10-21', null, 2, 'c', 'October 2026']],
            array_map(static fn (OccurrenceDates $dates): array => [
                $dates->start?->format('Y-m-d'),
                $dates->end?->format('Y-m-d'),
                $dates->session?->index,
                $dates->session?->firstRoundId,
                $dates->session?->label,
            ], $sessions),
        );
        self::assertSame([3, 3, 3], array_map(static fn (OccurrenceDates $dates): null|int => $dates->session?->count, $sessions));
        self::assertTrue($sessions[0]->hasRounds);
    }

    public function testASessionWithSeveralRoundsHasNoLabel(): void
    {
        $sessions = OccurrenceDates::sessions(true, null, null, [
            self::round('a', 'Solo', '2026-06-17 10:00', 'Europe/Prague'),
            self::round('b', 'Pairs', '2026-06-17 14:00', 'Europe/Prague'),
            self::round('c', 'Solo', '2026-07-15 10:00', 'Europe/Prague'),
        ]);

        self::assertCount(2, $sessions);
        self::assertNull($sessions[0]->session?->label);
        self::assertSame('a', $sessions[0]->session?->firstRoundId);
        self::assertSame('Solo', $sessions[1]->session?->label);
    }

    public function testAFridayToSundayChampionshipStaysOneSession(): void
    {
        $sessions = OccurrenceDates::sessions(true, self::day('2026-10-09'), null, [
            self::round('fri', 'Qualification', '2026-10-09 16:00', 'Europe/Prague'),
            self::round('sat', 'Semi-final', '2026-10-10 08:00', 'Europe/Prague'),
            self::round('sun', 'Final', '2026-10-11 08:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $sessions);
        self::assertNull($sessions[0]->session, 'one session behaves as before');
        self::assertSame(['2026-10-09', '2026-10-11'], [$sessions[0]->start?->format('Y-m-d'), $sessions[0]->end?->format('Y-m-d')]);
    }

    public function testARoundPastMidnightUtcLandsOnItsLocalDate(): void
    {
        // 02:00 UTC on 4 October is 22:00 on 3 October in New York; the next round, 4 October 23:00 UTC, is the 4th
        // there - one day apart, one session
        $sessions = OccurrenceDates::sessions(true, null, null, [
            self::round('a', 'September', '2026-09-16 23:00'),
            self::round('b', 'October', '2026-10-04 02:00'),
        ]);

        self::assertSame('2026-10-03', $sessions[1]->start?->format('Y-m-d'));

        $oneSession = OccurrenceDates::sessions(true, null, null, [
            self::round('a', 'Evening', '2026-10-04 02:00'),
            self::round('b', 'Next evening', '2026-10-04 23:00'),
        ]);

        self::assertCount(1, $oneSession);
        self::assertSame(['2026-10-03', '2026-10-04'], [$oneSession[0]->start?->format('Y-m-d'), $oneSession[0]->end?->format('Y-m-d')]);
    }

    public function testASessionIsLiveOnlyOnItsDayAndTheNextIsUpcoming(): void
    {
        [$september, $october, $november] = OccurrenceDates::sessions(true, null, null, [
            self::round('a', 'September', '2026-09-16 23:00'),
            self::round('b', 'October', '2026-10-04 02:00'),
            self::round('c', 'November', '2026-11-18 23:00'),
        ]);

        $onTheDay = new DateTimeImmutable('2026-10-03 12:00', new DateTimeZone('UTC'));
        self::assertSame(EventOccurrenceStatus::Past, $september->status($onTheDay, true, true));
        self::assertSame(EventOccurrenceStatus::Live, $october->status($onTheDay, true, true));
        self::assertSame(EventOccurrenceStatus::Upcoming, $november->status($onTheDay, true, true));

        $between = new DateTimeImmutable('2026-10-07 12:00', new DateTimeZone('UTC'));
        self::assertSame(EventOccurrenceStatus::Past, $october->status($between, true, true));
        self::assertSame(EventOccurrenceStatus::Upcoming, $november->status($between, true, true));
        self::assertSame($november, OccurrenceDates::current([$september, $october, $november], $between, true, true), '"You organize" shows the next one');
        self::assertSame($november, OccurrenceDates::current([$september, $october, $november], new DateTimeImmutable('2027-01-01'), true, true), 'else the last');
    }

    public function testAOneTimeEventWithRoundsOnOneDayKeepsItsOwnDates(): void
    {
        $sessions = OccurrenceDates::sessions(false, self::day('2026-10-10'), self::day('2026-10-11'), [
            self::round('a', 'Solo', '2026-10-10 09:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $sessions);
        self::assertSame(['2026-10-10', '2026-10-11'], [$sessions[0]->start?->format('Y-m-d'), $sessions[0]->end?->format('Y-m-d')]);

        $split = OccurrenceDates::sessions(false, self::day('2026-01-01'), self::day('2026-12-31'), [
            self::round('a', 'Spring', '2026-04-10 09:00', 'Europe/Prague'),
            self::round('b', 'Autumn', '2026-10-10 09:00', 'Europe/Prague'),
        ]);

        self::assertSame(['2026-04-10', '2026-10-10'], array_map(static fn (OccurrenceDates $dates): null|string => $dates->start?->format('Y-m-d'), $split), 'a one-time event splits too');
    }

    public function testALongSpanWithoutRoundsIsOngoingWhileItRuns(): void
    {
        $today = new DateTimeImmutable('2026-10-08 09:00', new DateTimeZone('UTC'));
        [$atomicClock] = OccurrenceDates::sessions(false, self::day('2026-10-06'), self::day('2027-12-07'), []);

        self::assertFalse($atomicClock->hasRounds);
        self::assertSame(EventOccurrenceStatus::Ongoing, $atomicClock->status($today, false, false));
        self::assertSame(EventOccurrenceStatus::Upcoming, $atomicClock->status(new DateTimeImmutable('2026-10-01'), false, false));
        self::assertSame(EventOccurrenceStatus::Past, $atomicClock->status(new DateTimeImmutable('2027-12-08'), false, false));

        [$month] = OccurrenceDates::sessions(true, self::day('2026-10-01'), self::day('2026-11-01'), []);
        self::assertSame(EventOccurrenceStatus::Live, $month->status($today, true, false), '31 days is still live');

        [$withRound] = OccurrenceDates::sessions(true, self::day('2026-10-01'), self::day('2027-12-07'), [self::round('a', 'Start', '2026-10-01 09:00', 'Europe/Prague')]);
        self::assertSame(EventOccurrenceStatus::Live, $withRound->status($today, true, false), 'a long span with a round stays live');
    }

    private static function round(string $id, string $name, string $startsAtUtc, string $zone = 'America/New_York'): OccurrenceRound
    {
        return new OccurrenceRound($id, $name, new DateTimeImmutable($startsAtUtc, new DateTimeZone('UTC')), $zone);
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
