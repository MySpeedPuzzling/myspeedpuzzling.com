<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CompetitionEvent;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Value\EventTitle;
use SpeedPuzzling\Web\Value\RoundCategory;

final class EventTitleTest extends TestCase
{
    private const string TODAY = '2026-09-30 12:00:00';

    public function testPastEventWithoutYearInNameGetsTheYear(): void
    {
        $title = EventTitle::forCompetition($this->competition('Festival Des Jeux', '2026-09-26', '2026-09-26'), null, [], $this->today());

        self::assertTrue($title->isPast);
        self::assertSame('2026', $title->year);
        self::assertSame('Festival Des Jeux 2026', $title->label());
    }

    public function testNameThatCarriesTheYearIsNotRepeated(): void
    {
        $title = EventTitle::forCompetition($this->competition('World Jigsaw Puzzle Championship 2026', '2026-09-16', '2026-09-20'), null, [], $this->today());

        self::assertTrue($title->isPast);
        self::assertNull($title->year);
        self::assertSame('World Jigsaw Puzzle Championship 2026', $title->label());
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function provideNamesWithAndWithoutYear(): iterable
    {
        yield 'year at the start' => ['2026 Wisconsin State Jigsaw Puzzle Championship', true];
        yield 'year glued to a shortcut' => ['PJM2026', true];
        yield 'year inside a date' => ['Individual 500 - November 23, 2026', true];
        yield 'piece count is not a year' => ['Teams 1000', false];
        yield 'two-digit year is not a year' => ["Puzzle Frenzy #7 - July '26", false];
        yield 'part of a longer number' => ['Event 120261', false];
    }

    #[DataProvider('provideNamesWithAndWithoutYear')]
    public function testYearIsAddedOnlyWhenTheNameHasNone(string $name, bool $nameHasYear): void
    {
        $title = EventTitle::forCompetition($this->competition($name, '2026-05-01', null), null, [], $this->today());

        self::assertSame($nameHasYear ? null : '2026', $title->year);
    }

    public function testUpcomingEventIsNotPast(): void
    {
        $title = EventTitle::forCompetition($this->competition('Danish Puzzle Marathon', '2026-10-24', '2026-10-24'), null, [], $this->today());

        self::assertFalse($title->isPast);
        self::assertSame('Danish Puzzle Marathon 2026', $title->label());
    }

    public function testEventEndingTodayIsNotPastYet(): void
    {
        $title = EventTitle::forCompetition($this->competition('Weekend Cup', '2026-09-28', '2026-09-30'), null, [], $this->today());

        self::assertFalse($title->isPast);
    }

    public function testMultiDayEventIsPastOnlyAfterItsLastDay(): void
    {
        // Started before today, ends after today - still running
        $running = EventTitle::forCompetition($this->competition('Long Cup', '2026-09-20', '2026-10-02'), null, [], $this->today());
        // Only a start date - it was a one-day event
        $oneDay = EventTitle::forCompetition($this->competition('Short Cup', '2026-09-29', null), null, [], $this->today());

        self::assertFalse($running->isPast);
        self::assertTrue($oneDay->isPast);
    }

    public function testUndatedEventIsNeitherPastNorGivenAYear(): void
    {
        $title = EventTitle::forCompetition($this->competition('Puzzle Discord France', null, null), null, [], $this->today());

        self::assertFalse($title->isPast);
        self::assertNull($title->year);
        self::assertNull($title->startsAt);
        self::assertSame('Puzzle Discord France', $title->label());
    }

    public function testEditionGetsItsSeriesInFront(): void
    {
        $title = EventTitle::forCompetition($this->competition('#21 - May 2026', '2026-05-31', '2026-05-31'), 'Piece-off', [], $this->today());

        self::assertSame('Piece-off · #21 - May 2026', $title->name);
        self::assertSame('Piece-off · #21 - May 2026', $title->label());
    }

    public function testEditionNamedAfterItsSeriesIsNotPrefixedTwice(): void
    {
        $title = EventTitle::forCompetition($this->competition('Puzzly #11', '2026-07-16', '2026-07-16'), 'puzzly', [], $this->today());

        self::assertSame('Puzzly #11', $title->name);
        self::assertSame('Puzzly #11 2026', $title->label());
    }

    public function testUndatedEditionIsDatedByItsRounds(): void
    {
        $rounds = [
            $this->round('2026-11-15 10:15:00'),
            $this->round('2026-06-14 05:15:00'),
        ];

        $upcoming = EventTitle::forCompetition($this->competition('Ou La La SPC No. 19', null, null), 'Ou La La Puzzles', $rounds, $this->today());

        self::assertFalse($upcoming->isPast, 'The last round is still to come');
        self::assertSame('2026', $upcoming->year);
        self::assertSame('2026-06-14', $upcoming->startsAt?->format('Y-m-d'), 'Starts with its earliest round');
        self::assertSame('Ou La La Puzzles · Ou La La SPC No. 19 2026', $upcoming->label());

        $past = EventTitle::forCompetition($this->competition('Virtual Competitions', null, null), 'NC Jigsaw Puzzle Association', [$this->round('2026-09-16 22:45:00')], $this->today());

        self::assertTrue($past->isPast);
    }

    public function testSaysResultsOnlyOnceOverAndWithResults(): void
    {
        $past = EventTitle::forCompetition($this->competition('Festival Des Jeux', '2026-09-26', '2026-09-26'), null, [], $this->today());
        $upcoming = EventTitle::forCompetition($this->competition('Danish Puzzle Marathon', '2026-10-24', '2026-10-24'), null, [], $this->today());

        self::assertTrue($past->saysResults(1));
        // Over, but nobody added a result here - named like an upcoming event
        self::assertFalse($past->saysResults(0));
        self::assertFalse($upcoming->saysResults(12));
    }

    public function testOwnDatesWinOverTheRounds(): void
    {
        $title = EventTitle::forCompetition(
            $this->competition('Cup', '2026-10-10', '2026-10-11'),
            null,
            [$this->round('2026-09-01 10:00:00')],
            $this->today(),
        );

        self::assertFalse($title->isPast);
        self::assertSame('2026-10-10', $title->startsAt?->format('Y-m-d'));
    }

    private function today(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::TODAY);
    }

    private function competition(string $name, null|string $dateFrom, null|string $dateTo): CompetitionEvent
    {
        return CompetitionEvent::fromDatabaseRow([
            'id' => '018d0004-0000-0000-0000-00000000ffff',
            'name' => $name,
            'shortcut' => null,
            'location' => null,
            'location_country_code' => null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'logo' => null,
            'description' => null,
            'link' => null,
            'registration_link' => null,
            'results_link' => null,
            'slug' => null,
            'tag_id' => null,
            'is_online' => false,
            'series_id' => null,
            'added_by_player_id' => null,
            'approved_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'created_at' => null,
        ]);
    }

    private function round(string $startsAt): EditionRoundDetail
    {
        return new EditionRoundDetail(
            id: '018d0005-0000-0000-0000-00000000ffff',
            name: 'Individual',
            startsAt: new DateTimeImmutable($startsAt),
            minutesLimit: 90,
            category: RoundCategory::Solo,
            badgeBackgroundColor: null,
            badgeTextColor: null,
            puzzles: [],
            color: '#000000',
            textColor: '#ffffff',
        );
    }
}
