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
