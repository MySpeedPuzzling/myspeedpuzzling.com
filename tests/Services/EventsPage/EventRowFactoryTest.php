<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventsPage\RowTag;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Tests\Services\EventDetail\PathUrlGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\RowContext;

/**
 * The row rules shared by the events page and the series page (detail-pages-plan.md 1.3). The events page's rules are
 * guarded by EventsPageBuilderTest; here the series page's and the organization page's contexts, and the Draft and
 * Who can enter tags (docs/features/organizations/README.md). Made-up events, a fixed "now".
 */
final class EventRowFactoryTest extends TestCase
{
    private const string NOW = '2026-06-10 12:00:00';

    public function testOnTheSeriesPageARowIsNamedByItsSessionWithoutRecurringPendingOrStar(): void
    {
        $session = $this->sprintSession(public: false);
        $factory = $this->factory();

        $events = $factory->row($session, EventOccurrenceStatus::Upcoming, 3, [], new EventsViewerData(followedSeriesIds: ['s-1']), EventsScope::fromQuery('cz', null), self::now(), self::today(), 'en', null);
        $series = $factory->row($session, EventOccurrenceStatus::Upcoming, 3, [], new EventsViewerData(followedSeriesIds: ['s-1']), EventsScope::fromQuery('cz', null), self::now(), self::today(), 'en', null, RowContext::SeriesPage);

        // The events page: the series name, the session under it, a star for the series, Recurring and the pending tag
        self::assertSame('Moonlight Sprint League', $events->title);
        self::assertSame('Season One · Sprint 3', $events->editionName);
        self::assertNotNull($events->followTarget);
        self::assertTrue($events->following);
        self::assertFalse($events->visible, 'an online row is not in the Czech scope');
        self::assertNull($events->time);
        self::assertSame([RowTagType::WaitingForApproval, RowTagType::Recurring], self::tagTypes($events->tags));

        // The series page: the session's own name, nothing under it, no star, always shown, the round's start time
        self::assertSame('Season One · Sprint 3', $series->title);
        self::assertNull($series->editionName);
        self::assertNull($series->followTarget);
        self::assertFalse($series->following);
        self::assertTrue($series->visible);
        self::assertSame([], $series->tags);
        self::assertNotNull($series->time);
        self::assertSame('2026-07-06T02:00:00Z', $series->time->isoInstant());
        self::assertSame('America/New_York', $series->time->zone);
        self::assertSame('/series/moonlight-sprint-league/season-one#round-sprint-3', $series->url);
        self::assertSame('s-ed-1', $series->manage?->id);
    }

    public function testAnEditionNamedLikeItsSeriesIsNamedOnTheSeriesPage(): void
    {
        $edition = new EventOccurrence(
            competitionId: 'c-1',
            name: 'Lakeside Marathon',
            slug: 'marathon',
            seriesId: 's-2',
            seriesName: 'Lakeside Marathon',
            seriesSlug: 'lakeside-marathon',
            startDate: self::day('2026-07-01'),
        );

        $row = $this->factory()->row($edition, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null, RowContext::SeriesPage);

        self::assertSame('Lakeside Marathon', $row->title);
        self::assertNull($row->editionName);
        self::assertNull($row->time, 'no rounds - no time');
    }

    public function testTheSeriesPagesArchiveLineLinksTheSessionAndHasItsOwnResults(): void
    {
        $session = $this->sprintSession(hasResults: true, start: '2026-04-08');

        $line = $this->factory()->archiveLine($session, 7, EventsScope::fromQuery('cz', null), 'en', RowContext::SeriesPage);

        self::assertSame('Season One · Sprint 3', $line->title);
        self::assertNull($line->editionName);
        self::assertTrue($line->hasResults);
        self::assertTrue($line->visible);
        self::assertSame('/series/moonlight-sprint-league/season-one#round-sprint-3', $line->url);
        self::assertSame([7], $line->indexIds);
    }

    public function testWhenNeverSaysTodayForOccurrences(): void
    {
        $day = self::today();

        self::assertSame(WhenLabel::LIVE, EventRowFactory::when(EventOccurrenceStatus::Live, $day, $day)?->type);
        self::assertSame(WhenLabel::TOMORROW, EventRowFactory::when(EventOccurrenceStatus::Upcoming, $day->modify('+1 day'), $day)?->type);
        // Wednesday 10 June 2026: Saturday is this weekend
        self::assertSame(WhenLabel::THIS_WEEKEND, EventRowFactory::when(EventOccurrenceStatus::Upcoming, $day->modify('+3 days'), $day)?->type);
        self::assertSame(WhenLabel::IN_DAYS, EventRowFactory::when(EventOccurrenceStatus::Upcoming, $day->modify('+13 days'), $day)?->type);
        self::assertNull(EventRowFactory::when(EventOccurrenceStatus::Upcoming, $day->modify('+31 days'), $day));
        self::assertNull(EventRowFactory::when(EventOccurrenceStatus::Past, $day->modify('-1 day'), $day));

        // An occurrence starting today is live, never "today" - only rounds say it (RoundsTimelineBuilder)
        foreach (EventOccurrenceStatus::cases() as $status) {
            foreach ([0, 1, 3, 13] as $days) {
                self::assertNotSame(WhenLabel::TODAY, EventRowFactory::when($status, $day->modify("+{$days} days"), $day)?->type);
            }
        }
    }

    public function testADraftSaysDraftInEveryContextAndNeverWaitingForApproval(): void
    {
        // A draft waiting for approval: only its team ever gets its row
        $draft = $this->sprintSession(public: false, isDraft: true);
        $factory = $this->factory();

        $events = $factory->row($draft, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null);
        $series = $factory->row($draft, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null, RowContext::SeriesPage);
        $organization = $factory->row($draft, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null, RowContext::OrganizationPage);

        self::assertSame([RowTagType::Draft, RowTagType::Recurring], self::tagTypes($events->tags));
        self::assertSame([RowTagType::Draft], self::tagTypes($series->tags));
        self::assertSame([RowTagType::Draft, RowTagType::Recurring], self::tagTypes($organization->tags));

        // isPending stays "not public" - the ⋯ menu decides what to offer from it
        self::assertTrue($events->isPending);
    }

    public function testAnApprovedDraftHasOnlyTheDraftTag(): void
    {
        $tags = $this->factory()->tags(
            new EventOccurrence(competitionId: 'c-3', name: 'Birchwood Test Night', slug: 'birchwood-test-night', startDate: self::day('2026-07-01'), isPublic: false, isDraft: true),
            EventOccurrenceStatus::Upcoming,
            false,
            0,
            self::now(),
        );

        self::assertSame([RowTagType::Draft], self::tagTypes($tags));
    }

    public function testWhoCanEnterFollowsRecurringInEveryContext(): void
    {
        $edition = new EventOccurrence(
            competitionId: 'c-2',
            name: 'Lantern Night One',
            slug: 'lantern-night-one',
            seriesId: 's-3',
            seriesName: 'Lantern Brewing Test Nights',
            seriesSlug: 'lantern-brewing-test-nights',
            startDate: self::day('2026-07-06'),
            hasRegistrationLink: true,
            eligibility: '18+',
        );
        $factory = $this->factory();

        $events = $factory->row($edition, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null);
        $series = $factory->row($edition, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null, RowContext::SeriesPage);
        $organization = $factory->row($edition, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::everywhere(), self::now(), self::today(), 'en', null, RowContext::OrganizationPage);

        self::assertSame([RowTagType::Recurring, RowTagType::Eligibility, RowTagType::Registration], self::tagTypes($events->tags));
        self::assertSame('18+', $events->tags[1]->text);
        self::assertSame([RowTagType::Eligibility, RowTagType::Registration], self::tagTypes($series->tags));
        self::assertSame('18+', $series->tags[0]->text);
        self::assertSame([RowTagType::Recurring, RowTagType::Eligibility, RowTagType::Registration], self::tagTypes($organization->tags));

        // A past occurrence keeps it too (the archive line has no tags, the row does)
        $past = $factory->tags($edition, EventOccurrenceStatus::Past, false, 0, self::now());
        self::assertSame([RowTagType::Recurring, RowTagType::Eligibility], self::tagTypes($past));
    }

    public function testOnTheOrganizationPageARowIsNamedAsOnTheEventsPageWithoutStarAndAlwaysShown(): void
    {
        $session = $this->sprintSession();

        $row = $this->factory()->row($session, EventOccurrenceStatus::Upcoming, 3, [], new EventsViewerData(followedSeriesIds: ['s-1']), EventsScope::fromQuery('cz', null), self::now(), self::today(), 'en', null, RowContext::OrganizationPage);

        // Named by its series with the session under it, Recurring kept (several series share the page)
        self::assertSame('Moonlight Sprint League', $row->title);
        self::assertSame('Season One · Sprint 3', $row->editionName);
        self::assertSame([RowTagType::Recurring], self::tagTypes($row->tags));
        // No star on the row - the series cards and the header carry them (P19)
        self::assertNull($row->followTarget);
        self::assertFalse($row->following);
        // Always shown, whatever the scope; the first round's start like the series page
        self::assertTrue($row->visible);
        self::assertNotNull($row->time);
        self::assertSame('2026-07-06T02:00:00Z', $row->time->isoInstant());
        self::assertSame('/series/moonlight-sprint-league/season-one#round-sprint-3', $row->url);
    }

    public function testOnTheOrganizationPageAOneTimeEventIsNamedByItself(): void
    {
        $event = new EventOccurrence(competitionId: 'c-4', name: 'Riverbend Test Open', slug: 'riverbend-test-open', countryCode: CountryCode::us, startDate: self::day('2026-08-01'));

        $row = $this->factory()->row($event, EventOccurrenceStatus::Upcoming, 0, [], null, EventsScope::fromQuery('cz', null), self::now(), self::today(), 'en', null, RowContext::OrganizationPage);

        self::assertSame('Riverbend Test Open', $row->title);
        self::assertNull($row->editionName);
        self::assertNull($row->followTarget);
        self::assertTrue($row->visible);
        self::assertNull($row->time, 'no rounds - no time');
        self::assertSame([], $row->tags);
    }

    public function testTheOrganizationPagesArchiveLineIsAlwaysShownAndNamedAsOnTheEventsPage(): void
    {
        $session = $this->sprintSession(hasResults: true, start: '2026-04-08');

        $line = $this->factory()->archiveLine($session, 7, EventsScope::fromQuery('cz', null), 'en', RowContext::OrganizationPage);

        self::assertSame('Moonlight Sprint League', $line->title);
        self::assertSame('Season One · Sprint 3', $line->editionName);
        self::assertTrue($line->visible);
        self::assertTrue($line->hasResults);
    }

    private function sprintSession(bool $public = true, bool $hasResults = false, string $start = '2026-07-05', bool $isDraft = false): EventOccurrence
    {
        $round = new OccurrenceRound('sprint-3', 'Sprint 3', new DateTimeImmutable($start . ' 22:00', new DateTimeZone('America/New_York'))->setTimezone(new DateTimeZone('UTC')), 'America/New_York');
        $sessions = OccurrenceDates::sessions(null, null, [
            new OccurrenceRound('sprint-1', 'Sprint 1', new DateTimeImmutable('2026-03-11 02:00', new DateTimeZone('UTC')), 'America/New_York'),
            $round,
        ]);
        $dates = $sessions[1];

        return new EventOccurrence(
            competitionId: 's-ed-1',
            name: 'Season One',
            slug: 'season-one',
            seriesId: 's-1',
            seriesName: 'Moonlight Sprint League',
            seriesSlug: 'moonlight-sprint-league',
            countryCode: CountryCode::us,
            isOnline: true,
            startDate: $dates->start,
            endDate: $dates->end,
            roundCount: 2,
            hasResults: $hasResults,
            isPublic: $public,
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
            isDraft: $isDraft,
        );
    }

    private function factory(): EventRowFactory
    {
        return new EventRowFactory(new EventUrls(new PathUrlGenerator()));
    }

    /**
     * @param list<RowTag> $tags
     *
     * @return list<RowTagType>
     */
    private static function tagTypes(array $tags): array
    {
        return array_map(static fn (RowTag $tag): RowTagType => $tag->type, $tags);
    }

    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::NOW, new DateTimeZone('UTC'));
    }

    private static function today(): DateTimeImmutable
    {
        return OccurrenceDates::today(self::now());
    }

    private static function day(string $date): DateTimeImmutable
    {
        return new DateTimeImmutable($date, new DateTimeZone('UTC'));
    }
}
