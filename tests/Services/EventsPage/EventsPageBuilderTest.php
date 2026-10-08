<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\CountryCount;
use SpeedPuzzling\Web\Results\EventsPage\EventsPage;
use SpeedPuzzling\Web\Results\EventsPage\RowTag;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\SeriesLine;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Results\EventsPage\YourEvent;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Services\EventsPage\EventsIndexFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Every rule of EventsPageBuilder (docs/features/events-page/implementation-plan.md, 1.5) on made-up occurrences.
 * Today is Wednesday 7 October 2026.
 */
final class EventsPageBuilderTest extends TestCase
{
    private const string TODAY = '2026-10-07 10:00:00';

    private int $sequence = 0;

    public function testSeveralEditionsOfASeriesInOneMonthAreOneRowButNotAcrossMonths(): void
    {
        $page = $this->build([
            $this->edition('s1', 'Session 1', '2026-11-05', series: 'Harbor Nights'),
            $this->event('Solo Cup', '2026-11-08'),
            $this->edition('s1', 'Session 2', '2026-11-12', series: 'Harbor Nights'),
            $this->edition('s1', 'Session 3', '2026-12-03', series: 'Harbor Nights'),
        ], [$this->series('s1', 'Harbor Nights')]);

        self::assertCount(2, $page->months);
        [$november, $december] = $page->months;

        self::assertSame([2026, 11], [$november->year, $november->month]);
        self::assertCount(2, $november->rows);
        $group = $november->rows[0];
        self::assertTrue($group->isGroup);
        self::assertSame('Harbor Nights', $group->title);
        self::assertCount(2, $group->sessions);
        self::assertSame(['Session 1', 'Session 2'], array_map(static fn ($session): string => $session->title, $group->sessions));
        self::assertSame('2026-11-05', $group->from);
        self::assertSame('2026-11-12', $group->to);
        self::assertSame('/series/harbor-nights', $group->url);
        self::assertEquals(FollowTarget::series('s1'), $group->followTarget);
        self::assertSame('series', $group->manage?->kind);
        self::assertSame([RowTagType::Recurring], $this->tagTypes($group));
        self::assertSame(3, $november->visibleCount, 'a group counts its sessions');

        self::assertCount(1, $december->rows);
        self::assertFalse($december->rows[0]->isGroup);
        self::assertSame('Session 3', $december->rows[0]->editionName);
    }

    public function testLiveEditionsAreNeverRolledUp(): void
    {
        $page = $this->build([
            $this->edition('s1', 'Week A', '2026-10-05', '2026-10-08', series: 'Weekly Relay'),
            $this->edition('s1', 'Week B', '2026-10-06', '2026-10-09', series: 'Weekly Relay'),
            $this->edition('s1', 'Week C', '2026-10-20', series: 'Weekly Relay'),
        ], [$this->series('s1', 'Weekly Relay')]);

        self::assertCount(2, $page->live);
        self::assertFalse($page->live[0]->isGroup);
        self::assertSame('Week A', $page->live[0]->editionName);
        self::assertCount(1, $page->months);
        self::assertFalse($page->months[0]->rows[0]->isGroup, 'one upcoming edition in the month stays a row');
    }

    public function testTheArchiveRollsUpEditionsOfAYearAndKeepsSingleLines(): void
    {
        $page = $this->build([
            $this->edition('s1', 'Spring', '2025-04-10', series: 'Harbor Nights'),
            $this->event('Valley Cup', '2025-05-01', resultsLink: true),
            $this->edition('s1', 'Summer', '2025-06-24', series: 'Harbor Nights'),
            $this->edition('s2', 'Only One', '2025-02-01', series: 'Mill Nights'),
            $this->event('Old Cup', '2024-03-14'),
        ], [$this->series('s1', 'Harbor Nights'), $this->series('s2', 'Mill Nights')]);

        self::assertSame([2025, 2024], array_map(static fn ($year): int => $year->year, $page->archiveYears));
        $lines = $page->archiveYears[0]->lines;

        self::assertCount(3, $lines);
        self::assertSame('Harbor Nights', $lines[0]->title, 'the roll-up sits at its newest edition');
        self::assertSame(2, $lines[0]->editionCount);
        self::assertSame('/series/harbor-nights', $lines[0]->url);
        self::assertSame([4, 6], [$lines[0]->monthFrom, $lines[0]->monthTo]);
        self::assertSame('Valley Cup', $lines[1]->title);
        self::assertTrue($lines[1]->hasResults);
        self::assertSame(1, $lines[1]->editionCount);
        self::assertSame('Mill Nights', $lines[2]->title);
        self::assertSame('Only One', $lines[2]->editionName);
        self::assertSame(1, $lines[2]->editionCount, 'a single edition stays its own line');
        self::assertSame(4, $page->archiveYears[0]->occurrenceCount());
    }

    public function testOnlineCountsUnderOnlineOnly(): void
    {
        $page = $this->build(
            [
                $this->edition('s1', 'Session 1', '2026-11-05', series: 'Harbor Nights', online: true, country: CountryCode::ca),
                $this->event('Maple Open', '2026-11-20', country: CountryCode::ca),
            ],
            [$this->series('s1', 'Harbor Nights', online: true, country: CountryCode::ca)],
            scope: EventsScope::country(CountryCode::ca),
        );

        self::assertSame(1, $page->onlineUpcoming);
        self::assertSame(2, $page->everywhereUpcoming);
        self::assertSame(1, $page->scopeUpcoming);
        self::assertSame(1, $page->summary->countries);
        self::assertTrue($page->summary->hasOnline);
        self::assertSame([['ca', 1]], array_map(static fn (CountryCount $count): array => [$count->code->name, $count->upcoming], $page->countryCounts));

        $rows = $page->months[0]->rows;
        self::assertSame('online', $rows[0]->scopeKey);
        self::assertFalse($rows[0]->visible, 'an online row is not in the Canada view');
        self::assertTrue($rows[0]->place->isOnline);
        self::assertTrue($rows[1]->visible);
        self::assertFalse($page->seriesOnline[0]->visible);
    }

    public function testItemsWaitingForApprovalAreRowsButNeverCounted(): void
    {
        $pending = $this->event('Pending Cup', '2026-11-05', public: false);
        $pendingPast = $this->event('Pending Old Cup', '2025-11-05', public: false);

        $page = $this->build(
            [$pending, $pendingPast, $this->event('Public Cup', '2026-11-06')],
            [$this->series('s9', 'Pending Series', public: false)],
            viewer: new EventsViewerData(goingCompetitionIds: [$pending->competitionId]),
        );

        $row = $page->months[0]->rows[0];
        self::assertSame('Pending Cup', $row->title);
        self::assertTrue($row->isPending);
        self::assertSame(RowTagType::WaitingForApproval, $row->tags[0]->type);
        self::assertSame(1, $page->months[0]->visibleCount);
        self::assertSame(1, $page->summary->upcomingDates);
        self::assertSame(0, $page->summary->series);
        self::assertSame([], $page->yourEvents);
        self::assertSame([], $page->archiveYears, 'a past item waiting for approval is nowhere');
        self::assertSame(1, $page->countryCounts[0]->upcoming);
        self::assertSame(['/events/public-cup'], $page->itemListUrls);
        self::assertTrue($page->seriesInPerson[0]->isPending);
    }

    public function testChipsOrderLimitAndTheActiveCountryAppended(): void
    {
        $occurrences = [];

        foreach (['de' => 3, 'cz' => 3, 'pl' => 2, 'at' => 1, 'fr' => 1, 'it' => 1, 'es' => 1, 'ro' => 0] as $code => $count) {
            for ($i = 0; $i < $count; $i++) {
                $occurrences[] = $this->event('Cup ' . $code . $i, '2026-11-1' . $i, country: CountryCode::fromCode($code));
            }
        }

        $occurrences[] = $this->event('Ro TBA', null, country: CountryCode::ro);

        $page = $this->build($occurrences, [], scope: EventsScope::country(CountryCode::ro), homeCountry: CountryCode::sk);

        // By upcoming, then name: Czechia before Germany (3 each), Poland, then Austria, France, Italy (Spain cut off)
        self::assertSame(['cz', 'de', 'pl', 'at', 'fr', 'it', 'ro'], array_map(static fn (CountryCount $count): string => $count->code->name, $page->chipCountries));
        self::assertSame(1, $page->chipCountries[6]->tba);
        self::assertSame(0, $page->chipCountries[6]->upcoming);

        self::assertNotNull($page->homeCountry);
        self::assertSame([CountryCode::sk, 0, 0, 0], [$page->homeCountry->code, $page->homeCountry->upcoming, $page->homeCountry->tba, $page->homeCountry->past]);

        self::assertSame(
            ['central_europe', 'western_europe', 'southern_europe', 'eastern_europe'],
            array_map(static fn ($group): string => $group->region->value, $page->regions),
        );
        self::assertSame(['cz', 'pl', 'at'], array_map(static fn (CountryCount $count): string => $count->code->name, $page->regions[0]->countries));
    }

    public function testWhenLabels(): void
    {
        $page = $this->build([
            $this->event('Live', '2026-10-06', '2026-10-08'),
            $this->event('Long', '2026-09-01', '2027-02-01', lastRound: '2027-02-01'),
            $this->event('Tomorrow', '2026-10-08'),
            $this->event('Friday', '2026-10-09'),
            $this->event('Monday', '2026-10-12'),
            $this->event('Eighteen', '2026-10-25'),
            $this->event('Thirty', '2026-11-06'),
            $this->event('Thirty-one', '2026-11-07'),
            $this->event('Next Sunday', '2026-10-18'),
        ], []);

        $when = [];

        foreach ([...$page->live, ...array_merge(...array_map(static fn ($month): array => $month->rows, $page->months))] as $row) {
            $when[$row->title] = $row->when === null ? null : [$row->when->type, $row->when->days, $row->when->soon];
        }

        self::assertSame([
            'Long' => [WhenLabel::LIVE, 0, false],
            'Live' => [WhenLabel::LIVE, 0, false],
            'Tomorrow' => [WhenLabel::TOMORROW, 1, true],
            'Friday' => [WhenLabel::THIS_WEEKEND, 2, true],
            'Monday' => [WhenLabel::IN_DAYS, 5, true],
            'Next Sunday' => [WhenLabel::IN_DAYS, 11, true],
            'Eighteen' => [WhenLabel::IN_DAYS, 18, false],
            'Thirty' => [WhenLabel::IN_DAYS, 30, false],
            'Thirty-one' => null,
        ], $when);
    }

    public function testOnASaturdayNextWeekendIsNotThisWeekend(): void
    {
        $page = $this->build([
            $this->event('Sunday', '2026-10-11'),
            $this->event('Next Friday', '2026-10-16'),
            $this->event('Next Saturday', '2026-10-17'),
        ], [], today: '2026-10-10 10:00:00');

        self::assertSame([
            'Sunday' => [WhenLabel::TOMORROW, 1, true],
            'Next Friday' => [WhenLabel::IN_DAYS, 6, true],
            'Next Saturday' => [WhenLabel::IN_DAYS, 7, true],
        ], $this->whenLabels($page));
    }

    public function testOnASundayNextWeekendIsNotThisWeekend(): void
    {
        $page = $this->build([
            $this->event('Monday', '2026-10-12'),
            $this->event('Next Friday', '2026-10-16'),
            $this->event('Next Sunday', '2026-10-18'),
        ], [], today: '2026-10-11 10:00:00');

        self::assertSame([
            'Monday' => [WhenLabel::TOMORROW, 1, true],
            'Next Friday' => [WhenLabel::IN_DAYS, 5, true],
            'Next Sunday' => [WhenLabel::IN_DAYS, 7, true],
        ], $this->whenLabels($page));
    }

    public function testOnAMondayTheComingFridayToSundayIsThisWeekend(): void
    {
        $page = $this->build([
            $this->event('Friday', '2026-10-16'),
            $this->event('Sunday', '2026-10-18'),
            $this->event('Next Monday', '2026-10-19'),
        ], [], today: '2026-10-12 10:00:00');

        self::assertSame([
            'Friday' => [WhenLabel::THIS_WEEKEND, 4, true],
            'Sunday' => [WhenLabel::THIS_WEEKEND, 6, true],
            'Next Monday' => [WhenLabel::IN_DAYS, 7, true],
        ], $this->whenLabels($page));
    }

    /**
     * @return array<string, null|array{string, int, bool}>
     */
    private function whenLabels(EventsPage $page): array
    {
        $when = [];

        foreach ([...$page->live, ...array_merge(...array_map(static fn ($month): array => $month->rows, $page->months))] as $row) {
            $when[$row->title] = $row->when === null ? null : [$row->when->type, $row->when->days, $row->when->soon];
        }

        return $when;
    }

    public function testTagsInOrderAndRegistrationStates(): void
    {
        $now = new DateTimeImmutable(self::TODAY, new DateTimeZone('UTC'));
        $full = $this->event('Full Open', '2026-11-01', managed: true, capacity: 2, opensAt: $now->modify('-5 days'));
        $notFull = $this->event('Open', '2026-11-02', managed: true, capacity: 10, opensAt: $now->modify('-5 days'));
        $opens = $this->event('Opens Later', '2026-11-03', managed: true, opensAt: new DateTimeImmutable('2026-10-12 22:30', new DateTimeZone('UTC')), country: CountryCode::de);
        $closed = $this->event('Closed', '2026-11-04', managed: true, closesAt: $now->modify('-1 day'));
        $external = $this->event('External', '2026-11-05', registrationLink: true);
        $goingThere = $this->event('Going There', '2026-11-06', registrationLink: true);
        // Rounds to its end - a long span its rounds do not define is ongoing, not a live row
        $longEdition = $this->edition('s1', 'Marathon', '2026-09-01', '2027-09-01', series: 'Marathon Series', public: false, lastRound: '2027-09-01');

        $page = $this->build(
            [$full, $notFull, $opens, $closed, $external, $goingThere, $longEdition],
            [$this->series('s1', 'Marathon Series', public: false)],
            goingCounts: [$full->competitionId => 2, $notFull->competitionId => 3, $goingThere->competitionId => 4, $longEdition->competitionId => 7],
            viewer: new EventsViewerData(goingCompetitionIds: [$goingThere->competitionId, $longEdition->competitionId]),
        );

        $rows = $this->rowsByTitle($page);

        self::assertSame([RowTagType::FullWaitlist, RowTagType::GoingCount], $this->tagTypes($rows['Full Open']));
        self::assertSame([RowTagType::RegistrationOpen, RowTagType::GoingCount], $this->tagTypes($rows['Open']));
        self::assertSame([RowTagType::RegistrationOpens], $this->tagTypes($rows['Opens Later']));
        // 22:30 UTC is already the next day in Berlin
        self::assertEquals(new DateTimeImmutable('2026-10-13', new DateTimeZone('UTC')), $rows['Opens Later']->tags[0]->date);
        self::assertSame([RowTagType::RegistrationClosed], $this->tagTypes($rows['Closed']));
        self::assertSame([RowTagType::Registration], $this->tagTypes($rows['External']));
        self::assertSame([RowTagType::Going, RowTagType::GoingCount], $this->tagTypes($rows['Going There']), 'no registration tag when going');
        self::assertSame(4, $rows['Going There']->tags[1]->count);
        self::assertSame(
            [RowTagType::WaitingForApproval, RowTagType::Going, RowTagType::Recurring, RowTagType::RunsUntil, RowTagType::GoingCount],
            $this->tagTypes($rows['Marathon Series']),
        );
        self::assertEquals(new DateTimeImmutable('2027-09-01', new DateTimeZone('UTC')), $rows['Marathon Series']->tags[3]->date);
        self::assertNull($rows['Marathon Series']->leaf->to, 'a long-running leaf shows its first day only');
        self::assertSame($rows['Marathon Series']->from, $rows['Marathon Series']->to);
    }

    public function testOngoingOnlineEventsCarryTheirRegistrationAndGoingCount(): void
    {
        $now = new DateTimeImmutable(self::TODAY, new DateTimeZone('UTC'));
        $relay = $this->event('Endless Relay', null, online: true, registrationLink: true);
        $fullRelay = $this->event('Full Relay', null, online: true, managed: true, capacity: 3, opensAt: $now->modify('-5 days'));

        $page = $this->build(
            [$relay, $fullRelay],
            [],
            goingCounts: [$relay->competitionId => 5, $fullRelay->competitionId => 3],
        );

        $rows = [];

        foreach ($page->ongoing as $row) {
            $rows[$row->title] = $row;
        }

        self::assertSame([RowTagType::Registration, RowTagType::GoingCount], $this->tagTypes($rows['Endless Relay']));
        self::assertSame(5, $rows['Endless Relay']->tags[1]->count);
        self::assertSame([RowTagType::FullWaitlist, RowTagType::GoingCount], $this->tagTypes($rows['Full Relay']));
    }

    public function testYourEvents(): void
    {
        $going = $this->event('Going Cup', '2026-11-20');
        $followedTba = $this->event('Followed TBA', null);
        $followedOngoing = $this->event('Followed Relay', null, online: true);
        $followedAndGoing = $this->event('Both Cup', '2026-11-02');
        $followedPast = $this->event('Followed Past', '2025-01-01');
        $seriesNext = $this->edition('s1', 'Session 1', '2026-11-05', series: 'Harbor Nights');
        $seriesLater = $this->edition('s1', 'Session 2', '2026-11-12', series: 'Harbor Nights');
        $seriesLaterGoing = $this->edition('s1', 'Session 3', '2026-12-12', series: 'Harbor Nights');

        $page = $this->build(
            [$going, $followedTba, $followedOngoing, $followedAndGoing, $followedPast, $seriesNext, $seriesLater, $seriesLaterGoing],
            [$this->series('s1', 'Harbor Nights')],
            viewer: new EventsViewerData(
                goingCompetitionIds: [$going->competitionId, $followedAndGoing->competitionId, $seriesLaterGoing->competitionId],
                followedCompetitionIds: [$followedTba->competitionId, $followedOngoing->competitionId, $followedAndGoing->competitionId, $followedPast->competitionId],
                followedSeriesIds: ['s1'],
            ),
        );

        self::assertSame(
            [
                ['Both Cup', YourEvent::MARK_GOING],
                ['Harbor Nights', YourEvent::MARK_FOLLOWING],
                ['Going Cup', YourEvent::MARK_GOING],
                ['Harbor Nights', YourEvent::MARK_GOING],
                ['Followed Relay', YourEvent::MARK_FOLLOWING],
                ['Followed TBA', YourEvent::MARK_FOLLOWING],
            ],
            array_map(static fn (YourEvent $event): array => [$event->row->title, $event->mark], $page->yourEvents),
        );
        self::assertSame('Session 1', $page->yourEvents[1]->row->editionName);
        self::assertSame('Session 3', $page->yourEvents[3]->row->editionName);
        self::assertTrue($page->yourEvents[1]->row->following);
    }

    /**
     * docs/features/organizations/README.md "Follow": a followed organization adds its upcoming one-time events and the
     * next date of each of its series, deduplicated against what is followed directly - only while it is public
     */
    public function testYourEventsThroughAFollowedOrganization(): void
    {
        $riverbend = new OrganizationRef('o1', 'Riverbend Jigsaw Association', 'RJA', 'riverbend', isPublic: true);
        $draftClub = new OrganizationRef('o2', 'Harbor Puzzle Club', null, 'harbor', isPublic: false);
        $notFollowed = new OrganizationRef('o3', 'Maple Leaf Puzzlers', null, 'maple', isPublic: true);

        $page = $this->build(
            [
                $this->event('Org Open', '2026-11-20', organization: $riverbend),
                $this->event('Org Past', '2025-02-01', organization: $riverbend),
                $this->edition('s1', 'Night 1', '2026-11-05', series: 'Lantern Nights', organization: $riverbend),
                $this->edition('s1', 'Night 2', '2026-11-12', series: 'Lantern Nights', organization: $riverbend),
                // Followed directly too: one row
                $this->edition('s2', 'Meet 1', '2026-11-07', series: 'Club Meets', organization: $riverbend),
                $this->event('Draft Club Cup', '2026-11-08', organization: $draftClub),
                $this->event('Maple Cup', '2026-11-09', organization: $notFollowed),
                // An organization never shows what is not public itself
                $this->event('Pending Org Cup', '2026-11-10', public: false, organization: $riverbend),
            ],
            [$this->series('s1', 'Lantern Nights'), $this->series('s2', 'Club Meets')],
            viewer: new EventsViewerData(
                followedSeriesIds: ['s2'],
                followedOrganizationIds: ['o1', 'o2'],
            ),
        );

        self::assertSame(
            [
                ['Lantern Nights', 'Night 1', YourEvent::MARK_FOLLOWING],
                ['Club Meets', 'Meet 1', YourEvent::MARK_FOLLOWING],
                ['Org Open', null, YourEvent::MARK_FOLLOWING],
            ],
            array_map(static fn (YourEvent $event): array => [$event->row->title, $event->row->editionName, $event->mark], $page->yourEvents),
        );
    }

    public function testSeriesDirectory(): void
    {
        $page = $this->build(
            [
                $this->edition('next', 'A', '2026-12-01', series: 'Next Later'),
                $this->edition('next', 'Undated', null, series: 'Next Later'),
                $this->edition('soon', 'A', '2026-10-20', series: 'Next Sooner'),
                $this->edition('soon', 'B', '2025-10-20', series: 'Next Sooner'),
                $this->edition('live', 'A', '2026-10-01', '2026-12-01', series: 'Live Now', lastRound: '2026-12-01'),
                $this->edition('ongoing', 'A', '2026-09-01', '2027-09-01', series: 'Ongoing Marathon'),
                $this->edition('old', 'A', '2024-01-01', series: 'Last Old'),
                $this->edition('recent', 'A', '2026-01-01', series: 'Last Recent'),
            ],
            [
                $this->series('none', 'No Editions'),
                $this->series('next', 'Next Later'),
                $this->series('soon', 'Next Sooner'),
                $this->series('live', 'Live Now'),
                $this->series('ongoing', 'Ongoing Marathon'),
                $this->series('old', 'Last Old'),
                $this->series('recent', 'Last Recent'),
                $this->series('online', 'Online One', online: true),
            ],
        );

        self::assertSame(
            [
                ['Ongoing Marathon', SeriesNext::ONGOING, 1],
                ['Live Now', SeriesNext::LIVE, 1],
                ['Next Sooner', SeriesNext::NEXT, 2],
                ['Next Later', SeriesNext::NEXT, 2],
                ['Last Recent', SeriesNext::LAST, 1],
                ['Last Old', SeriesNext::LAST, 1],
                ['No Editions', SeriesNext::NONE, 0],
            ],
            array_map(static fn (SeriesLine $line): array => [$line->name, $line->next->type, $line->editionCount], $page->seriesInPerson),
        );
        self::assertSame(['Online One'], array_map(static fn (SeriesLine $line): string => $line->name, $page->seriesOnline));
        self::assertSame(8, $page->summary->series);

        // Series lines follow the occurrences in the index
        foreach ([...$page->seriesInPerson, ...$page->seriesOnline] as $line) {
            self::assertSame('s', $page->index[$line->indexId]['k']);
            self::assertSame($line->name, $page->index[$line->indexId]['n']);
        }
    }

    /**
     * An edition with a round a month (one at 02:00 UTC = the evening before in New York) must not be one span "live"
     * for months: each round day is a session of its own. Made-up names and dates.
     */
    public function testRoundsOnSeparateDaysAreSessionsAndTheNextOneIsUpcoming(): void
    {
        $sessions = $this->editionSessions('lc', 'Night Owl Rounds', ['2026-05-12 23:00', '2026-06-09 23:00', '2026-07-14 23:00', '2026-08-11 23:00', '2026-09-29 02:00', '2026-10-27 23:00'], series: 'Lantern Puzzle Club');
        $page = $this->build($sessions, [$this->series('lc', 'Lantern Puzzle Club', online: true, country: CountryCode::us)]);

        self::assertSame([], $page->live, 'nothing is on today');
        self::assertCount(1, $page->months);
        self::assertSame([2026, 10], [$page->months[0]->year, $page->months[0]->month]);
        $row = $page->months[0]->rows[0];
        self::assertFalse($row->isGroup);
        self::assertSame('Lantern Puzzle Club', $row->title);
        self::assertSame('Night Owl Rounds · Round 6', $row->editionName, 'a single-round session carries the round name');
        self::assertSame('2026-10-27', $row->from);
        self::assertSame('/series/lantern-puzzle-club/night-owl-rounds#round-' . $sessions[5]->session?->firstRoundId, $row->url);
        self::assertSame([WhenLabel::IN_DAYS, 20], [$row->when?->type, $row->when?->days]);

        // The series counts one edition, next on 27 Oct
        $line = $page->seriesOnline[0];
        self::assertSame(1, $line->editionCount);
        self::assertSame(SeriesNext::NEXT, $line->next->type);
        self::assertSame('2026-10-27', $line->next->date?->format('Y-m-d'));

        // The five past sessions of the one edition are one archive line - one event held, not "5 editions"; the
        // newest is 28 Sep, the evening before 02:00 UTC
        $archiveLine = $page->archiveYears[0]->lines[0];
        self::assertFalse($archiveLine->isRollUp());
        self::assertSame(1, $archiveLine->editionCount);
        self::assertCount(5, $archiveLine->indexIds);
        self::assertSame(['Lantern Puzzle Club', 'Night Owl Rounds'], [$archiveLine->title, $archiveLine->editionName]);
        self::assertSame('/series/lantern-puzzle-club/night-owl-rounds', $archiveLine->url);
        self::assertSame(['2026-05-12', '2026-09-28'], [$archiveLine->from->format('Y-m-d'), $archiveLine->to?->format('Y-m-d')]);
        self::assertSame(1, $page->archiveYears[0]->occurrenceCount(), 'the year counts events, not sessions');

        // One index entry per session, each its own status
        $entries = array_values(array_filter($page->index, static fn (array $entry): bool => $entry['k'] === 'd'));
        self::assertSame(['past', 'past', 'past', 'past', 'past', 'upcoming'], array_column($entries, 'st'));
        self::assertSame('2026-09-28', $entries[4]['f']);
        self::assertSame(['/series/lantern-puzzle-club/night-owl-rounds'], $page->itemListUrls, 'the page once, without a fragment');
    }

    public function testTheArchiveCountsEditionsNotSessions(): void
    {
        $page = $this->build([
            ...$this->editionSessions('lc', 'Spring Rounds', ['2025-03-10 18:00', '2025-04-14 18:00'], series: 'Lantern Puzzle Club'),
            ...$this->editionSessions('lc', 'Autumn Rounds', ['2025-09-08 18:00', '2025-10-13 18:00', '2025-11-10 18:00'], series: 'Lantern Puzzle Club'),
            $this->event('Valley Cup', '2025-05-01'),
        ], [$this->series('lc', 'Lantern Puzzle Club', online: true, country: CountryCode::us)]);

        $year = $page->archiveYears[0];
        $rollUp = $year->lines[0];
        self::assertTrue($rollUp->isRollUp());
        self::assertSame(2, $rollUp->editionCount, '"2 editions in 2025", not 5');
        self::assertCount(5, $rollUp->indexIds);
        self::assertSame(3, $year->occurrenceCount(), 'two editions and a one-time event');
    }

    public function testASessionIsLiveOnItsOwnDaysOnly(): void
    {
        $sessions = $this->editionSessions('lc', 'Night Owl Rounds', ['2026-08-25 23:00', '2026-09-29 02:00', '2026-10-27 23:00'], series: 'Lantern Puzzle Club');
        $series = [$this->series('lc', 'Lantern Puzzle Club', online: true, country: CountryCode::us)];

        // 28 September (UTC) is the second session's day in New York
        $onTheDay = $this->build($sessions, $series, today: '2026-09-28 12:00:00');
        self::assertCount(1, $onTheDay->live);
        self::assertSame('Night Owl Rounds · Round 2', $onTheDay->live[0]->editionName);
        self::assertSame(WhenLabel::LIVE, $onTheDay->live[0]->when?->type);
        self::assertSame('2026-10-27', $onTheDay->months[0]->rows[0]->from, 'the next session is upcoming');

        $dayAfter = $this->build($sessions, $series, today: '2026-09-29 12:00:00');
        self::assertSame([], $dayAfter->live);
        self::assertSame('2026-10-27', $dayAfter->months[0]->rows[0]->from);
    }

    public function testAFridayToSundayChampionshipStaysOneSession(): void
    {
        $sessions = $this->editionSessions('mc', 'Meadow Open 2026', ['2026-10-09 07:00', '2026-10-11 07:00'], series: 'Meadow Open', online: false, zone: 'Europe/Prague');

        self::assertCount(1, $sessions, 'no Saturday round, still one session');
        self::assertNull($sessions[0]->session);

        $page = $this->build($sessions, [$this->series('mc', 'Meadow Open')]);
        $row = $page->months[0]->rows[0];

        self::assertSame(['2026-10-09', '2026-10-11'], [$row->from, $row->to]);
        self::assertSame('Meadow Open 2026', $row->editionName, 'no session label');
        self::assertSame('/series/meadow-open/meadow-open-2026', $row->url, 'no fragment');
        self::assertSame(WhenLabel::THIS_WEEKEND, $row->when?->type);

        $saturday = $this->build($sessions, [$this->series('mc', 'Meadow Open')], today: '2026-10-10 12:00:00');
        self::assertCount(1, $saturday->live, 'live on the Saturday between the rounds');
        self::assertSame([], $saturday->months);
    }

    public function testSessionsOfOneEditionInOneMonthAreOneRowWithAChipEach(): void
    {
        $sessions = $this->editionSessions('lc', 'Autumn Cup', ['2026-11-05 18:00', '2026-11-19 18:00'], series: 'Lantern Puzzle Club');
        $page = $this->build($sessions, [$this->series('lc', 'Lantern Puzzle Club', online: true, country: CountryCode::us)]);

        $row = $page->months[0]->rows[0];
        self::assertTrue($row->isGroup);
        self::assertSame(['Autumn Cup · Round 1', 'Autumn Cup · Round 2'], array_map(static fn ($session): string => $session->title, $row->sessions));
        self::assertSame('/series/lantern-puzzle-club/autumn-cup#round-' . $sessions[1]->session?->firstRoundId, $row->sessions[1]->url);
        self::assertNotSame($row->sessions[0]->indexId, $row->sessions[1]->indexId);
    }

    public function testYourEventsShowOnlyTheNextSessionOfACompetition(): void
    {
        $sessions = $this->editionSessions('lc', 'Night Owl Rounds', ['2026-08-25 23:00', '2026-09-29 02:00', '2026-10-27 23:00', '2026-11-24 23:00'], series: 'Lantern Puzzle Club');
        $series = [$this->series('lc', 'Lantern Puzzle Club', online: true, country: CountryCode::us)];
        $viewer = new EventsViewerData(goingCompetitionIds: [$sessions[0]->competitionId]);

        $page = $this->build($sessions, $series, viewer: $viewer);
        self::assertCount(1, $page->yourEvents);
        self::assertSame('2026-10-27', $page->yourEvents[0]->row->from);
        self::assertSame(YourEvent::MARK_GOING, $page->yourEvents[0]->mark);

        $onTheDay = $this->build($sessions, $series, viewer: $viewer, today: '2026-09-28 12:00:00');
        self::assertCount(1, $onTheDay->yourEvents);
        self::assertSame('2026-09-28', $onTheDay->yourEvents[0]->row->from, 'the live session');

        $following = $this->build($sessions, $series, viewer: new EventsViewerData(followedSeriesIds: ['lc']));
        self::assertCount(1, $following->yourEvents);
        self::assertSame(YourEvent::MARK_FOLLOWING, $following->yourEvents[0]->mark);
    }

    /**
     * A 14-month event without rounds - or with one opening round only - is not "Live" for 14 months
     */
    public function testALongSpanItsRoundsDoNotDefineIsOngoingNotLive(): void
    {
        $marathon = $this->event('Endless Tick Marathon', '2026-10-05', '2027-11-30');
        $opening = $this->event('One Opening Round', '2026-10-01', '2027-12-01', lastRound: '2026-10-01');
        $month = $this->event('Month Long', '2026-10-01', '2026-11-01');
        $overAMonth = $this->event('Over A Month', '2026-10-01', '2026-11-02');
        $withRounds = $this->event('League With Rounds', '2026-10-01', '2027-03-01', lastRound: '2027-03-01');
        $coming = $this->event('Coming Marathon', '2026-11-10', '2027-05-01');

        $page = $this->build([$marathon, $opening, $month, $overAMonth, $withRounds, $coming], [], viewer: new EventsViewerData(goingCompetitionIds: [$marathon->competitionId]));

        self::assertSame(['Endless Tick Marathon', 'One Opening Round', 'Over A Month'], array_map(static fn (AgendaRow $row): string => $row->title, $page->ongoing));
        self::assertSame(['League With Rounds', 'Month Long'], array_map(static fn (AgendaRow $row): string => $row->title, $page->live));
        self::assertSame(['Coming Marathon'], array_map(static fn (AgendaRow $row): string => $row->title, $page->months[0]->rows), 'it is upcoming until it starts');

        $row = $page->ongoing[0];
        self::assertNull($row->when);
        self::assertContains(RowTagType::RunsUntil, $this->tagTypes($row));
        self::assertSame('2026-10-05', $row->from);

        $entry = $page->index[$row->indexIds[0]];
        self::assertSame(['ongoing', true, '2026-10-05', '2027-11-30'], [$entry['st'], $entry['lr'], $entry['f'], $entry['t']], 'the calendar draws its bar');
        self::assertSame(['Endless Tick Marathon'], array_map(static fn (YourEvent $event): string => $event->row->title, $page->yourEvents));
        self::assertSame(6, $page->summary->upcomingDates, 'a long span running now counts - live, upcoming and running');
        self::assertSame(6, $page->countryCounts[0]->upcoming, 'its country is never "nothing planned"');
    }

    public function testAScopeWithOnlyARunningLongSpanIsCounted(): void
    {
        $page = $this->build([$this->event('Endless Tick Marathon', '2026-10-05', '2027-11-30', country: CountryCode::at)], [], scope: EventsScope::country(CountryCode::at));

        self::assertSame(1, $page->scopeUpcoming);
        self::assertSame(['at'], array_map(static fn (CountryCount $count): string => $count->code->name, $page->chipCountries));
        self::assertSame(1, $page->chipCountries[0]->upcoming);
    }


    public function testItemListUrlsInAgendaOrder(): void
    {
        $page = $this->build([
            $this->event('Live Cup', '2026-10-06', '2026-10-08'),
            $this->edition('s1', 'Session 1', '2026-11-05', series: 'Harbor Nights'),
            $this->edition('s1', 'Session 2', '2026-11-12', series: 'Harbor Nights'),
            $this->event('Pending', '2026-11-13', public: false),
            $this->event('Past Cup', '2026-01-13'),
            $this->event('Tba Cup', null),
            $this->event('Relay', null, online: true),
        ], [$this->series('s1', 'Harbor Nights')]);

        self::assertSame(
            ['/events/live-cup', '/series/harbor-nights/session-1', '/series/harbor-nights/session-2', '/events/tba-cup'],
            $page->itemListUrls,
        );
    }

    public function testTheIndexHasAnEntryPerShownOccurrenceAndSeries(): void
    {
        $page = $this->build([
            $this->edition('s1', 'Session 1', '2026-11-05', series: 'Harbor Nights', online: true, country: CountryCode::ca),
            $this->edition('s1', 'Undated', null, series: 'Harbor Nights', online: true, country: CountryCode::ca),
        ], [$this->series('s1', 'Harbor Nights', online: true, country: CountryCode::ca)]);

        self::assertCount(2, $page->index, 'the undated edition is not in the index');
        self::assertSame(0, $page->index[0]['id']);
        self::assertSame('d', $page->index[0]['k']);
        self::assertSame(1, $page->index[0]['sid']);
        self::assertSame('s', $page->index[1]['k']);
        self::assertSame([0], $page->months[0]->rows[0]->indexIds);
        self::assertSame(2, $page->seriesOnline[0]->editionCount, 'the undated edition counts in its series');
    }

    public function testPlaceShowsTheCountryAloneWhenTheLocationHoldsIt(): void
    {
        self::assertNull(EventsPageBuilder::place(false, 'Hamburg, Germany', CountryCode::de, 'en')->city);
        self::assertNull(EventsPageBuilder::place(false, 'Brno, Česko', CountryCode::cz, 'cs')->city);
        self::assertNull(EventsPageBuilder::place(false, 'Brno, Czechia', CountryCode::cz, 'en')->city);

        $place = EventsPageBuilder::place(false, 'Prague', CountryCode::cz, 'de');
        self::assertSame('Prague', $place->city);
        self::assertSame('Tschechien', $place->country);

        $online = EventsPageBuilder::place(true, 'Online', CountryCode::ca, 'en');
        self::assertTrue($online->isOnline);
        self::assertNull($online->city);
        self::assertNull($online->country);
    }

    public function testTheEmptyStateNamesTheLastEventOfTheScope(): void
    {
        $page = $this->build([
            $this->event('Prague Cup', '2025-05-01', country: CountryCode::cz),
            $this->event('Brno Cup', '2026-02-01', country: CountryCode::cz),
            $this->event('Berlin Cup', '2026-09-01', country: CountryCode::de),
        ], [], scope: EventsScope::country(CountryCode::cz));

        self::assertSame(0, $page->scopeUpcoming);
        self::assertSame('Brno Cup', $page->scopeLast?->title);
    }

    public function testTheArchiveOfAYear(): void
    {
        $occurrences = [
            $this->edition('s1', 'Spring', '2025-04-10', series: 'Harbor Nights'),
            $this->edition('s1', 'Summer', '2025-06-24', series: 'Harbor Nights'),
            $this->event('Valley Cup', '2024-05-01'),
            $this->event('Pending Old', '2023-05-01', public: false),
            $this->event('Coming', '2026-11-01'),
        ];
        $builder = $this->builder();
        $today = new DateTimeImmutable(self::TODAY, new DateTimeZone('UTC'));

        $archive = $builder->buildArchive($occurrences, 2025, $today, 'en');

        self::assertNotNull($archive);
        self::assertSame([2025, 2024], $archive->years);
        self::assertSame(2, $archive->count);
        self::assertCount(1, $archive->lines);
        self::assertSame(2, $archive->lines[0]->editionCount);
        // Newest first, like the lines
        self::assertSame(['/series/harbor-nights/summer', '/series/harbor-nights/spring'], $archive->itemListUrls);

        self::assertNull($builder->buildArchive($occurrences, 2023, $today, 'en'), 'only items waiting for approval');
        self::assertNull($builder->buildArchive($occurrences, 2026, $today, 'en'), 'nothing past yet');
        self::assertNull($builder->buildArchive($occurrences, 1999, $today, 'en'));
    }

    /**
     * @param list<EventOccurrence> $occurrences
     * @param list<EventSeriesRow> $series
     * @param array<string, int> $goingCounts
     */
    private function build(
        array $occurrences,
        array $series,
        array $goingCounts = [],
        null|EventsViewerData $viewer = null,
        null|EventsScope $scope = null,
        null|CountryCode $homeCountry = null,
        string $today = self::TODAY,
    ): EventsPage {
        usort($occurrences, static fn (EventOccurrence $a, EventOccurrence $b): int => [$a->startDate === null, $a->startDate, $a->name] <=> [$b->startDate === null, $b->startDate, $b->name]);

        return $this->builder()->build(
            $occurrences,
            $series,
            $goingCounts,
            $viewer,
            $scope ?? EventsScope::everywhere(),
            new DateTimeImmutable($today, new DateTimeZone('UTC')),
            'en',
            $homeCountry,
        );
    }

    private function builder(): EventsPageBuilder
    {
        $urlGenerator = new class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                $values = array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $parameters);

                return match ($name) {
                    'event_detail' => '/events/' . ($values['slug'] ?? ''),
                    'edition_detail' => '/series/' . ($values['seriesSlug'] ?? '') . '/' . ($values['editionSlug'] ?? ''),
                    'competition_series_detail' => '/series/' . ($values['slug'] ?? ''),
                    default => '/' . $name . '/' . implode('/', $values),
                };
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        $translator = new class implements TranslatorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function trans(string $id, array $parameters = [], null|string $domain = null, null|string $locale = null): string
            {
                return $id === 'events_page.place.online' ? 'Online' : $id;
            }

            public function getLocale(): string
            {
                return 'en';
            }
        };

        return new EventsPageBuilder(new EventUrls($urlGenerator), new EventsIndexFactory($translator));
    }

    private function event(
        string $name,
        null|string $from,
        null|string $to = null,
        bool $online = false,
        null|CountryCode $country = CountryCode::cz,
        bool $public = true,
        bool $resultsLink = false,
        bool $registrationLink = false,
        bool $managed = false,
        null|int $capacity = null,
        null|DateTimeImmutable $opensAt = null,
        null|DateTimeImmutable $closesAt = null,
        // the day of its last round - none: no rounds
        null|string $lastRound = null,
        null|OrganizationRef $organization = null,
    ): EventOccurrence {
        return new EventOccurrence(
            competitionId: $this->nextId(),
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            organization: $organization,
            location: $online ? null : 'Town',
            countryCode: $country,
            isOnline: $online,
            startDate: self::day($from),
            endDate: self::day($to),
            hasRegistrationLink: $registrationLink,
            registrationManaged: $managed,
            capacity: $capacity,
            registrationOpensAt: $opensAt,
            roundCount: $lastRound !== null ? 1 : 0,
            lastRoundDay: self::day($lastRound),
            registrationClosesAt: $closesAt,
            hasResults: $resultsLink,
            isPublic: $public,
        );
    }

    private function edition(
        string $seriesId,
        string $name,
        null|string $from,
        null|string $to = null,
        string $series = 'Series',
        bool $online = false,
        null|CountryCode $country = CountryCode::cz,
        bool $public = true,
        // the day of its last round - none: no rounds
        null|string $lastRound = null,
        null|OrganizationRef $organization = null,
    ): EventOccurrence {
        return new EventOccurrence(
            competitionId: $this->nextId(),
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            organization: $organization,
            seriesId: $seriesId,
            seriesName: $series,
            seriesSlug: strtolower(str_replace(' ', '-', $series)),
            location: $online ? null : 'Town',
            countryCode: $country,
            isOnline: $online,
            startDate: self::day($from),
            endDate: self::day($to),
            roundCount: $lastRound !== null ? 1 : 0,
            lastRoundDay: self::day($lastRound),
            isPublic: $public,
        );
    }

    /**
     * The occurrences GetEventOccurrences makes of one edition whose rounds start at $roundStarts (UTC instants, read in
     * $zone): one per session.
     *
     * @param list<string> $roundStarts
     *
     * @return list<EventOccurrence>
     */
    private function editionSessions(string $seriesId, string $name, array $roundStarts, string $series = 'Series', bool $online = true, string $zone = 'America/New_York'): array
    {
        $competitionId = $this->nextId();
        $rounds = [];

        foreach ($roundStarts as $number => $startsAt) {
            $rounds[] = new OccurrenceRound('r-' . $competitionId . '-' . ($number + 1), 'Round ' . ($number + 1), new DateTimeImmutable($startsAt, new DateTimeZone('UTC')), $zone);
        }

        return array_map(static fn (OccurrenceDates $dates): EventOccurrence => new EventOccurrence(
            competitionId: $competitionId,
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            seriesId: $seriesId,
            seriesName: $series,
            seriesSlug: strtolower(str_replace(' ', '-', $series)),
            countryCode: $online ? CountryCode::us : CountryCode::cz,
            location: $online ? null : 'Town',
            isOnline: $online,
            startDate: $dates->start,
            endDate: $dates->end,
            roundCount: count($rounds),
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
        ), OccurrenceDates::sessions(null, null, $rounds));
    }

    private function series(string $id, string $name, bool $online = false, null|CountryCode $country = CountryCode::cz, bool $public = true): EventSeriesRow
    {
        return new EventSeriesRow(
            id: $id,
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            isOnline: $online,
            location: $online ? null : 'Town',
            countryCode: $country,
            isPublic: $public,
        );
    }

    private function nextId(): string
    {
        $this->sequence++;

        return sprintf('018d0099-0000-0000-0000-%012d', $this->sequence);
    }

    private static function day(null|string $date): null|DateTimeImmutable
    {
        return $date === null ? null : new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }

    /**
     * @return list<RowTagType>
     */
    private function tagTypes(AgendaRow $row): array
    {
        return array_map(static fn (RowTag $tag): RowTagType => $tag->type, $row->tags);
    }

    /**
     * @return array<string, AgendaRow>
     */
    private function rowsByTitle(EventsPage $page): array
    {
        $rows = [];

        foreach ([...$page->live, ...array_merge(...array_map(static fn ($month): array => $month->rows, $page->months)), ...$page->tba] as $row) {
            $rows[$row->title] = $row;
        }

        return $rows;
    }
}
