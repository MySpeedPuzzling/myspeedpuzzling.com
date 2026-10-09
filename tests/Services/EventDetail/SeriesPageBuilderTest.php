<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventDetail;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\CompetitionSeriesOverview;
use SpeedPuzzling\Web\Results\EventDetail\SeriesPage;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Services\EventDetail\SeriesPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;

/**
 * The series page's rules (detail-pages-plan.md 1.3): one list of sessions, the Next card, months, ongoing, date not
 * set, past by year, the facts, the follow star and the JSON-LD sub-events. Synthetic occurrences, a fixed "now".
 */
final class SeriesPageBuilderTest extends TestCase
{
    // Wednesday
    private const string NOW = '2026-06-10 12:00:00';

    private int $ids = 0;

    public function testTheFirstUpcomingSessionIsNextAndNotRepeated(): void
    {
        $sessions = $this->sessions('Season One', ['2026-04-15', '2026-05-13', '2026-06-17', '2026-07-15', '2026-07-29']);

        $page = $this->build($sessions);

        self::assertNotNull($page->next);
        self::assertSame('Season One · Round 3', $page->next->row->title);
        self::assertSame(WhenLabel::IN_DAYS, $page->next->row->when?->type);
        self::assertSame(7, $page->next->row->when->days);
        self::assertNotNull($page->next->row->time, 'the start time of its round');

        // The Next one is not repeated below; July holds two dates
        self::assertSame([[2026, 7]], array_map(static fn ($month): array => [$month->year, $month->month], $page->months));
        self::assertSame(['Season One · Round 4', 'Season One · Round 5'], self::titles($page->months[0]->rows));
        self::assertSame(3, $page->facts->comingCount);
        self::assertFalse($page->facts->nextIsLive);
        self::assertSame('2026-06-17', $page->facts->next?->format('Y-m-d'));
    }

    public function testALiveSessionWinsOverAnUpcomingOneAndLiveRowsComeFirst(): void
    {
        $liveA = $this->edition('Today A', '2026-06-10');
        $liveB = $this->edition('Today B', '2026-06-10');
        $upcoming = $this->edition('Tomorrow', '2026-06-11');

        $page = $this->build([$liveA, $liveB, $upcoming]);

        self::assertSame('Today A', $page->next?->row->title);
        self::assertSame(WhenLabel::LIVE, $page->next->row->when?->type);
        self::assertTrue($page->facts->nextIsLive);
        self::assertSame(['Today B', 'Tomorrow'], self::titles($page->months[0]->rows));
    }

    public function testALongSpanIsOngoingAndNeverNext(): void
    {
        $long = $this->edition('All year', '2026-01-01', '2026-12-31');
        $undatedB = $this->edition('Zeta to be planned', null);
        $undatedA = $this->edition('Alpha to be planned', null);

        $page = $this->build([$long, $undatedB, $undatedA]);

        self::assertNull($page->next);
        self::assertSame([], $page->months);
        self::assertSame(['All year'], self::titles($page->ongoing));
        self::assertSame([RowTagType::RunsUntil], array_map(static fn ($tag): RowTagType => $tag->type, $page->ongoing[0]->tags));
        self::assertSame(['Alpha to be planned', 'Zeta to be planned'], self::titles($page->dateNotSet), 'by name');
        self::assertSame(3, $page->facts->editionCount, 'undated editions count');
        self::assertSame(0, $page->facts->comingCount);
    }

    public function testThePastIsOneLinePerSessionByYearNewestFirst(): void
    {
        $sessions = $this->sessions('Season One', ['2025-11-12', '2026-03-11', '2026-04-08'], resultsOn: [1]);
        $oldEdition = $this->edition('Opening night', '2025-02-01');

        $page = $this->build([$oldEdition, ...$sessions]);

        self::assertSame([2026, 2025], array_map(static fn ($year): int => $year->year, $page->pastYears));
        self::assertSame(['Season One · Round 3', 'Season One · Round 2'], self::lineTitles($page->pastYears[0]->lines));
        self::assertSame(['Season One · Round 1', 'Opening night'], self::lineTitles($page->pastYears[1]->lines));
        // Results per session, not per competition
        self::assertSame([false, true], array_map(static fn (ArchiveLine $line): bool => $line->hasResults, $page->pastYears[0]->lines));
        self::assertStringEndsWith('#round-' . $sessions[2]->firstRound?->id, (string) $page->pastYears[0]->lines[0]->url);
        self::assertSame('2025-02-01', $page->facts->since?->format('Y-m-d'));
        self::assertSame(2, $page->facts->editionCount);
        self::assertSame(4, $page->pastCount());
    }

    public function testFollowingAndThePublicStar(): void
    {
        $sessions = [$this->edition('Session 1', '2026-07-01')];
        $viewer = new EventsViewerData(goingCompetitionIds: [strtolower($sessions[0]->competitionId)], followedSeriesIds: ['s-1']);

        $page = $this->build($sessions, viewer: $viewer);
        self::assertSame('series:s-1', $page->followTarget?->toString());
        self::assertTrue($page->following);
        self::assertTrue($page->next?->isGoing);
        self::assertSame([RowTagType::Going], array_map(static fn ($tag): RowTagType => $tag->type, $page->next->row->tags));
        self::assertNull($page->next->row->followTarget, 'no star on rows - the header has it');

        $pending = $this->build($sessions, viewer: $viewer, approved: false);
        self::assertNull($pending->followTarget);
        self::assertFalse($pending->following);
    }

    public function testJsonLdSubEventsArePublicDatedSessions(): void
    {
        $sessions = $this->sessions('Season One', ['2026-05-13', '2026-06-17']);
        $undated = $this->edition('Later', null);
        $pending = $this->edition('Not yet approved', '2026-07-01', public: false);

        $page = $this->build([...$sessions, $undated, $pending]);

        self::assertSame(['Sprint Series · Season One · Round 1', 'Sprint Series · Season One · Round 2'], array_map(static fn ($sub): string => $sub->name, $page->subEvents));
        self::assertStringEndsWith('#round-' . $sessions[1]->firstRound?->id, $page->subEvents[1]->path);
        self::assertSame('2026-06-17', $page->subEvents[1]->startDate->format('Y-m-d'));
    }

    /**
     * A series with many sessions (high-frequency-series.md "Series page for 200+ editions"): the filter bar's
     * categories, months and what each row carries; the past years in month sections, the newest month of each open
     */
    public function testASeriesWithManySessionsGetsTheFilterAndMonthSections(): void
    {
        $occurrences = [
            $this->jam('Jam No. 1', '2025-11-20'),
            $this->jam('Jam No. 2', '2025-12-04'),
            $this->jam('Jam No. 3', '2025-12-18', 'duo'),
        ];

        foreach (['01-08', '01-22', '02-05', '02-19', '03-05', '03-19', '04-02', '04-16', '05-07'] as $number => $day) {
            $occurrences[] = $this->jam('Jam No. ' . (4 + $number), '2026-' . $day);
        }

        $copper = $this->jam('Jam No. 13', '2026-05-21', puzzles: ['Copper Lighthouse', 'Ærø Harbor']);
        $occurrences[] = $copper;
        $occurrences[] = $this->jam('Jam No. 14', '2026-06-17');
        $teams = $this->jam('Jam No. 15', '2026-07-01', 'team');
        $occurrences[] = $teams;
        $undated = $this->edition('Summer Special', null);
        $occurrences[] = $undated;

        $page = $this->build($occurrences);
        $filter = $page->filter;

        self::assertNotNull($filter);
        self::assertSame(['solo', 'duo', 'team'], $page->categories);
        self::assertTrue($page->showsCategories());
        // Jam No. 14 is the Next card - its month is not in the list below it
        self::assertSame(['2026-07'], array_map(static fn (DateTimeImmutable $month): string => $month->format('Y-m'), $filter->upcomingMonths));
        self::assertSame(
            ['2026-05', '2026-04', '2026-03', '2026-02', '2026-01', '2025-12', '2025-11'],
            array_map(static fn (DateTimeImmutable $month): string => $month->format('Y-m'), $filter->pastMonths),
        );

        $copperLine = $page->pastYears[0]->lines[0];
        $details = $page->detailsOf($copperLine->indexIds);
        self::assertNotNull($details);
        self::assertSame(['solo'], $details->categories);
        self::assertSame(['Copper Lighthouse', 'Ærø Harbor'], $details->puzzleNames);
        self::assertSame('jam no. 13 copper lighthouse aero harbor', $details->search);
        self::assertSame('2026-05', $details->month);

        $teamsRow = $page->months[0]->rows[0];
        self::assertSame(['team'], $page->detailsOf($teamsRow->indexIds)?->categories);
        self::assertSame(['', [], []], [
            $page->detailsOf($page->dateNotSet[0]->indexIds)?->month,
            $page->detailsOf($page->dateNotSet[0]->indexIds)?->categories,
            $page->detailsOf($page->dateNotSet[0]->indexIds)?->puzzleNames,
        ]);
        self::assertNotNull($page->next);
        self::assertSame(['solo'], $page->detailsOf($page->next->row->indexIds)?->categories, 'the Next card too');

        // Month sections, newest month open in each year
        self::assertSame(['2026-05', '2026-04', '2026-03', '2026-02', '2026-01'], array_map(static fn ($month): string => $month->key(), $page->monthsOf(2026)));
        self::assertSame([true, false, false, false, false], array_map(static fn ($month): bool => $month->open, $page->monthsOf(2026)));
        self::assertSame(['Jam No. 13', 'Jam No. 12'], self::lineTitles($page->monthsOf(2026)[0]->lines));
        self::assertSame([true, false], array_map(static fn ($month): bool => $month->open, $page->monthsOf(2025)));
    }

    public function testASeriesWithFewSessionsHasNoFilter(): void
    {
        $page = $this->build($this->sessions('Season One', ['2026-04-15', '2026-05-13', '2026-06-17', '2026-07-15']));

        self::assertNull($page->filter);
        self::assertSame([], $page->monthsOf(2026));
        self::assertLessThan(SeriesPageBuilder::FILTER_FROM_SESSIONS, 4);
        // Its rows still name their rounds' puzzles (none here) - and one category says nothing
        self::assertNotNull($page->next);
        self::assertSame([], $page->detailsOf($page->next->row->indexIds)?->puzzleNames);
        self::assertFalse($page->showsCategories());
    }

    /**
     * At most 50 sub-events (P26): every coming session, then the newest past ones - by date
     */
    public function testJsonLdSubEventsAreCapped(): void
    {
        $occurrences = [];

        for ($i = 0; $i < 200; $i++) {
            $occurrences[] = $this->edition('Jam No. ' . ($i + 1), new DateTimeImmutable('2026-06-01', new DateTimeZone('UTC'))->modify(sprintf('-%d days', 3 * (199 - $i)))->format('Y-m-d'));
        }

        $occurrences[] = $this->edition('Jam No. 201', '2026-06-12');
        $occurrences[] = $this->edition('Jam No. 202', '2026-06-15');

        $page = $this->build($occurrences);

        self::assertCount(SeriesPageBuilder::MAX_JSON_LD_SUB_EVENTS, $page->subEvents);
        self::assertSame('Sprint Series · Jam No. 153', $page->subEvents[0]->name, 'the 48 newest past ones');
        self::assertSame('Sprint Series · Jam No. 202', $page->subEvents[49]->name);
        $days = array_map(static fn ($sub): string => $sub->startDate->format('Y-m-d'), $page->subEvents);
        $sorted = $days;
        sort($sorted);
        self::assertSame($sorted, $days, 'by date');
    }

    /**
     * "Add my time" in the header (P27) once a public edition has started
     */
    public function testAddMyTimeOnceAPublicEditionHasStarted(): void
    {
        self::assertFalse($this->build([$this->edition('Next week', '2026-06-17')])->hasStartedEdition);
        self::assertFalse($this->build([$this->edition('Not approved', '2026-05-01', public: false), $this->edition('Undated', null)])->hasStartedEdition);
        self::assertTrue($this->build([$this->edition('Today', '2026-06-10')])->hasStartedEdition, 'live');
        self::assertTrue($this->build([$this->edition('Last month', '2026-05-01'), $this->edition('Next week', '2026-06-17')])->hasStartedEdition);
        self::assertTrue($this->build([$this->edition('All year', '2026-01-01', '2026-12-31')])->hasStartedEdition, 'a long span running');
    }

    public function testAnEmptySeries(): void
    {
        $page = $this->build([]);

        self::assertTrue($page->isEmpty());
        self::assertNull($page->next);
        self::assertSame(0, $page->facts->editionCount);
        self::assertNull($page->facts->since);
    }

    public function testComingCompetitionIdsForTheGoingCounts(): void
    {
        $sessions = $this->sessions('Season One', ['2026-05-13', '2026-06-17', '2026-07-15']);
        $past = $this->edition('Old', '2026-01-01');
        $long = $this->edition('All year', '2026-01-01', '2026-12-31');

        self::assertSame(
            [strtolower($sessions[0]->competitionId), strtolower($long->competitionId)],
            SeriesPageBuilder::comingCompetitionIds([...$sessions, $past, $long], self::now()),
        );
    }

    /**
     * @param list<EventOccurrence> $occurrences
     */
    private function build(array $occurrences, null|EventsViewerData $viewer = null, bool $approved = true): SeriesPage
    {
        usort($occurrences, static fn (EventOccurrence $a, EventOccurrence $b): int => [$a->startDate === null, $a->startDate, $a->name] <=> [$b->startDate === null, $b->startDate, $b->name]);

        $urls = new EventUrls(new PathUrlGenerator());

        return new SeriesPageBuilder(new EventRowFactory($urls), $urls)->build(
            new CompetitionSeriesOverview(
                id: 's-1',
                name: 'Sprint Series',
                slug: 'sprint-series',
                logo: null,
                description: null,
                link: 'https://example.com',
                isOnline: true,
                location: null,
                locationCountryCode: CountryCode::us,
                addedByPlayerId: null,
                approvedAt: $approved ? self::now() : null,
            ),
            $occurrences,
            [],
            $viewer,
            self::now(),
            'en',
        );
    }

    /**
     * One edition with a round on each day (22:00 New York) - one occurrence per session, as GetEventOccurrences makes them
     *
     * @param list<string> $days
     * @param list<int> $resultsOn the rounds (0-based) with results
     *
     * @return list<EventOccurrence>
     */
    private function sessions(string $name, array $days, array $resultsOn = []): array
    {
        $competitionId = 'c-' . (++$this->ids);
        $rounds = [];

        foreach ($days as $number => $day) {
            $rounds[] = new OccurrenceRound(
                id: $competitionId . '-r' . ($number + 1),
                name: 'Round ' . ($number + 1),
                startsAt: new DateTimeImmutable($day . ' 22:00', new DateTimeZone('America/New_York'))->setTimezone(new DateTimeZone('UTC')),
                zone: 'America/New_York',
                hasResults: in_array($number, $resultsOn, true),
            );
        }

        return array_map(static fn (OccurrenceDates $dates): EventOccurrence => new EventOccurrence(
            competitionId: $competitionId,
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            seriesId: 's-1',
            seriesName: 'Sprint Series',
            seriesSlug: 'sprint-series',
            countryCode: CountryCode::us,
            isOnline: true,
            startDate: $dates->start,
            endDate: $dates->end,
            roundCount: count($rounds),
            hasResults: (bool) $dates->session?->hasResults,
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
        ), OccurrenceDates::sessions(null, null, $rounds));
    }

    private function edition(string $name, null|string $from, null|string $to = null, bool $public = true): EventOccurrence
    {
        return new EventOccurrence(
            competitionId: 'c-' . (++$this->ids),
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            seriesId: 's-1',
            seriesName: 'Sprint Series',
            seriesSlug: 'sprint-series',
            countryCode: CountryCode::us,
            isOnline: true,
            startDate: $from !== null ? new DateTimeImmutable($from, new DateTimeZone('UTC')) : null,
            endDate: $to !== null ? new DateTimeImmutable($to, new DateTimeZone('UTC')) : null,
            isPublic: $public,
        );
    }

    /**
     * An edition of one round (20:00 Berlin) of $category with these revealed puzzles
     *
     * @param list<string> $puzzles
     */
    private function jam(string $name, string $day, string $category = 'solo', array $puzzles = []): EventOccurrence
    {
        $round = new OccurrenceRound(
            id: 'r-' . ($this->ids + 1),
            name: ucfirst($category),
            startsAt: new DateTimeImmutable($day . ' 20:00', new DateTimeZone('Europe/Berlin'))->setTimezone(new DateTimeZone('UTC')),
            zone: 'Europe/Berlin',
            category: $category,
            puzzleNames: $puzzles,
        );

        return new EventOccurrence(
            competitionId: 'c-' . (++$this->ids),
            name: $name,
            slug: strtolower(str_replace([' ', '.'], ['-', ''], $name)),
            seriesId: 's-1',
            seriesName: 'Sprint Series',
            seriesSlug: 'sprint-series',
            isOnline: true,
            startDate: new DateTimeImmutable($day, new DateTimeZone('UTC')),
            roundCount: 1,
            lastRoundDay: new DateTimeImmutable($day, new DateTimeZone('UTC')),
            firstRound: $round,
            zone: 'Europe/Berlin',
            rounds: [$round],
        );
    }

    /**
     * @param list<AgendaRow> $rows
     *
     * @return list<string>
     */
    private static function titles(array $rows): array
    {
        return array_map(static fn (AgendaRow $row): string => $row->title, $rows);
    }

    /**
     * @param list<ArchiveLine> $lines
     *
     * @return list<string>
     */
    private static function lineTitles(array $lines): array
    {
        return array_map(static fn (ArchiveLine $line): string => $line->title, $lines);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }
}
