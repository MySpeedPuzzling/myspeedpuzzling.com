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
 * sessions, a long span its rounds do not define is ongoing. Made-up events and dates.
 */
final class OccurrenceDatesTest extends TestCase
{
    public function testMonthlyRoundsAreSeparateSessions(): void
    {
        $sessions = OccurrenceDates::sessions(self::day('2026-05-12'), self::day('2026-10-27'), [
            self::round('a', 'May sprint', '2026-05-12 23:00'),
            self::round('b', 'June sprint', '2026-06-09 23:00'),
            self::round('c', 'October sprint', '2026-10-27 23:00'),
        ]);

        self::assertSame(
            [['2026-05-12', null, 0, 'a', 'May sprint'], ['2026-06-09', null, 1, 'b', 'June sprint'], ['2026-10-27', null, 2, 'c', 'October sprint']],
            array_map(static fn (OccurrenceDates $dates): array => [
                $dates->start?->format('Y-m-d'),
                $dates->end?->format('Y-m-d'),
                $dates->session?->index,
                $dates->session?->firstRoundId,
                $dates->session?->label,
            ], $sessions),
        );
        self::assertSame([3, 3, 3], array_map(static fn (OccurrenceDates $dates): null|int => $dates->session?->count, $sessions));
        self::assertSame('2026-05-12', $sessions[0]->lastRoundDay?->format('Y-m-d'));
    }

    public function testASessionWithSeveralRoundsHasNoLabel(): void
    {
        $sessions = OccurrenceDates::sessions(null, null, [
            self::round('a', 'Solo', '2026-05-12 10:00', 'Europe/Prague'),
            self::round('b', 'Pairs', '2026-05-12 14:00', 'Europe/Prague'),
            self::round('c', 'Solo', '2026-06-09 10:00', 'Europe/Prague'),
        ]);

        self::assertCount(2, $sessions);
        self::assertNull($sessions[0]->session?->label);
        self::assertSame('a', $sessions[0]->session?->firstRoundId);
        self::assertSame('Solo', $sessions[1]->session?->label);
    }

    public function testAFridayToSundayChampionshipStaysOneSession(): void
    {
        $sessions = OccurrenceDates::sessions(self::day('2026-10-09'), null, [
            self::round('fri', 'Qualification', '2026-10-09 16:00', 'Europe/Prague'),
            self::round('sat', 'Semi-final', '2026-10-10 08:00', 'Europe/Prague'),
            self::round('sun', 'Final', '2026-10-11 08:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $sessions);
        self::assertNull($sessions[0]->session, 'one session behaves as before');
        self::assertSame(['2026-10-09', '2026-10-11'], [$sessions[0]->start?->format('Y-m-d'), $sessions[0]->end?->format('Y-m-d')]);
    }

    public function testFridayAndSundayWithoutASaturdayRoundAreOneSession(): void
    {
        $sessions = OccurrenceDates::sessions(self::day('2026-10-09'), self::day('2026-10-11'), [
            self::round('fri', 'Qualification', '2026-10-09 14:00', 'Europe/Prague'),
            self::round('sun', 'Final', '2026-10-11 06:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $sessions);
        self::assertSame(EventOccurrenceStatus::Live, $sessions[0]->status(new DateTimeImmutable('2026-10-10 12:00', new DateTimeZone('UTC')), false, false), 'Saturday is not a gap');
    }

    public function testEveryRoundInsideADeclaredShortSpanIsOneSession(): void
    {
        // A 3-day span with rounds on its first and last day
        $threeDays = OccurrenceDates::sessions(self::day('2026-11-06'), self::day('2026-11-08'), [
            self::round('a', 'Opening', '2026-11-06 09:00', 'Europe/Prague'),
            self::round('b', 'Final', '2026-11-08 09:00', 'Europe/Prague'),
        ]);
        self::assertCount(1, $threeDays);
        self::assertSame(['2026-11-06', '2026-11-08'], [$threeDays[0]->start?->format('Y-m-d'), $threeDays[0]->end?->format('Y-m-d')]);

        // A week-long festival: rounds 5 days apart are still one session
        $week = OccurrenceDates::sessions(self::day('2026-11-02'), self::day('2026-11-08'), [
            self::round('a', 'Opening', '2026-11-02 09:00', 'Europe/Prague'),
            self::round('b', 'Final', '2026-11-07 09:00', 'Europe/Prague'),
        ]);
        self::assertCount(1, $week);

        // The same rounds without a declared week are two sessions
        self::assertCount(2, OccurrenceDates::sessions(null, null, [
            self::round('a', 'Opening', '2026-11-02 09:00', 'Europe/Prague'),
            self::round('b', 'Final', '2026-11-07 09:00', 'Europe/Prague'),
        ]));
    }

    public function testARoundPastMidnightUtcLandsOnItsLocalDate(): void
    {
        // 02:00 UTC on 29 September is 22:00 on 28 September in New York
        $sessions = OccurrenceDates::sessions(null, null, [
            self::round('a', 'August', '2026-08-25 23:00'),
            self::round('b', 'September', '2026-09-29 02:00'),
        ]);

        self::assertSame('2026-09-28', $sessions[1]->start?->format('Y-m-d'));

        $oneSession = OccurrenceDates::sessions(null, null, [
            self::round('a', 'Evening', '2026-09-29 02:00'),
            self::round('b', 'Next evening', '2026-09-29 23:00'),
        ]);

        self::assertCount(1, $oneSession);
        self::assertSame(['2026-09-28', '2026-09-29'], [$oneSession[0]->start?->format('Y-m-d'), $oneSession[0]->end?->format('Y-m-d')]);
    }

    public function testASessionIsLiveOnlyOnItsDayAndTheNextIsUpcoming(): void
    {
        [$august, $september, $november] = OccurrenceDates::sessions(null, null, [
            self::round('a', 'August', '2026-08-25 23:00'),
            self::round('b', 'September', '2026-09-29 02:00'),
            self::round('c', 'November', '2026-11-24 23:00'),
        ]);

        $onTheDay = new DateTimeImmutable('2026-09-28 12:00', new DateTimeZone('UTC'));
        self::assertSame(EventOccurrenceStatus::Past, $august->status($onTheDay, true, true));
        self::assertSame(EventOccurrenceStatus::Live, $september->status($onTheDay, true, true));
        self::assertSame(EventOccurrenceStatus::Upcoming, $november->status($onTheDay, true, true));

        $between = new DateTimeImmutable('2026-10-07 12:00', new DateTimeZone('UTC'));
        self::assertSame(EventOccurrenceStatus::Past, $september->status($between, true, true));
        self::assertSame(EventOccurrenceStatus::Upcoming, $november->status($between, true, true));
        self::assertSame($november, OccurrenceDates::current([$august, $september, $november], $between, true, true), '"You organize" shows the next one');
        self::assertSame($november, OccurrenceDates::current([$august, $september, $november], new DateTimeImmutable('2027-01-01'), true, true), 'else the last');
    }

    public function testAOneTimeEventIsDatedLikeAnEdition(): void
    {
        // Its round's day, not date_from - the edition rule
        $sessions = OccurrenceDates::sessions(self::day('2026-10-09'), self::day('2026-10-11'), [
            self::round('a', 'Solo', '2026-10-10 09:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $sessions);
        self::assertSame(['2026-10-10', '2026-10-11'], [$sessions[0]->start?->format('Y-m-d'), $sessions[0]->end?->format('Y-m-d')]);

        $split = OccurrenceDates::sessions(self::day('2026-01-01'), self::day('2026-12-31'), [
            self::round('a', 'Spring', '2026-04-10 09:00', 'Europe/Prague'),
            self::round('b', 'Autumn', '2026-10-10 09:00', 'Europe/Prague'),
        ]);

        self::assertSame(['2026-04-10', '2026-10-10'], array_map(static fn (OccurrenceDates $dates): null|string => $dates->start?->format('Y-m-d'), $split), 'rounds on separate days split it');

        [$noRounds] = OccurrenceDates::sessions(null, self::day('2026-10-11'), []);
        self::assertSame(['2026-10-11', null], [$noRounds->start?->format('Y-m-d'), $noRounds->end?->format('Y-m-d')]);
    }

    public function testALongSpanWithoutRoundsIsOngoingWhileItRuns(): void
    {
        $today = new DateTimeImmutable('2026-10-08 09:00', new DateTimeZone('UTC'));
        [$marathon] = OccurrenceDates::sessions(self::day('2026-10-05'), self::day('2027-11-30'), []);

        self::assertNull($marathon->lastRoundDay);
        self::assertSame(EventOccurrenceStatus::Ongoing, $marathon->status($today, false, false));
        self::assertSame(EventOccurrenceStatus::Upcoming, $marathon->status(new DateTimeImmutable('2026-10-01'), false, false));
        self::assertSame(EventOccurrenceStatus::Past, $marathon->status(new DateTimeImmutable('2027-12-01'), false, false));

        [$month] = OccurrenceDates::sessions(self::day('2026-10-01'), self::day('2026-11-01'), []);
        self::assertSame(EventOccurrenceStatus::Live, $month->status($today, true, false), '31 days is still live');
    }

    public function testOneOpeningRoundDoesNotMakeALongSpanLive(): void
    {
        $today = new DateTimeImmutable('2026-10-08 09:00', new DateTimeZone('UTC'));

        // 1 Oct 2026 to 1 Dec 2027 with one round on the first day - an edition and a one-time event alike
        [$opening] = OccurrenceDates::sessions(self::day('2026-10-01'), self::day('2027-12-01'), [self::round('a', 'Start', '2026-10-01 09:00', 'Europe/Prague')]);
        self::assertSame('2027-12-01', $opening->end?->format('Y-m-d'));
        self::assertTrue($opening->isLongSpan());
        self::assertSame(EventOccurrenceStatus::Ongoing, $opening->status($today, true, false));
        self::assertSame(EventOccurrenceStatus::Ongoing, $opening->status($today, false, false));

        // A league with a round every other day from 1 Sep to 3 Oct (one session), date_to 30 Oct: the rounds define
        // the span - the 27 days after the last one are no long span
        $rounds = [];

        for ($day = 0; $day <= 32; $day += 2) {
            $rounds[] = self::round('r' . $day, 'Day ' . $day, self::day('2026-09-01')->modify('+' . $day . ' days')->format('Y-m-d') . ' 09:00', 'Europe/Prague');
        }

        $league = OccurrenceDates::sessions(self::day('2026-09-01'), self::day('2026-10-30'), $rounds);
        self::assertCount(1, $league);
        self::assertSame(['2026-09-01', '2026-10-30'], [$league[0]->start?->format('Y-m-d'), $league[0]->end?->format('Y-m-d')]);
        self::assertFalse($league[0]->isLongSpan());
        self::assertSame(EventOccurrenceStatus::Live, $league[0]->status($today, true, false));
    }

    public function testEverySessionKnowsItsFirstRound(): void
    {
        $sessions = OccurrenceDates::sessions(null, null, [
            self::round('a', 'May sprint', '2026-05-13 02:00'),
            self::round('b', 'June sprint', '2026-06-10 02:00'),
            self::round('c', 'June relay', '2026-06-10 04:00'),
        ]);

        self::assertSame(['a', 'b'], array_map(static fn (OccurrenceDates $dates): null|string => $dates->firstRound?->id, $sessions));
    }

    public function testOneSessionKnowsItsFirstRoundAndNoRoundsNone(): void
    {
        $one = OccurrenceDates::sessions(self::day('2026-10-09'), self::day('2026-10-11'), [
            self::round('sat', 'Semi-final', '2026-10-10 08:00', 'Europe/Prague'),
            self::round('fri', 'Qualification', '2026-10-09 16:00', 'Europe/Prague'),
        ]);

        self::assertCount(1, $one);
        self::assertSame('fri', $one[0]->firstRound?->id);
        self::assertNull(OccurrenceDates::sessions(self::day('2026-10-09'), null, [])[0]->firstRound);
    }

    public function testASessionHasResultsWhenOneOfItsRoundsHas(): void
    {
        $sessions = OccurrenceDates::sessions(null, null, [
            new OccurrenceRound('a', 'May', new DateTimeImmutable('2026-05-13 02:00', new DateTimeZone('UTC')), 'America/New_York', hasResults: true),
            new OccurrenceRound('b', 'June', new DateTimeImmutable('2026-06-10 02:00', new DateTimeZone('UTC')), 'America/New_York'),
        ]);

        self::assertSame([true, false], array_map(static fn (OccurrenceDates $dates): bool => (bool) $dates->session?->hasResults, $sessions));
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
