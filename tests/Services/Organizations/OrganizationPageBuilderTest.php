<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Organizations;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\RowTag;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Results\Organizations\OrganizationEventCard;
use SpeedPuzzling\Web\Results\Organizations\OrganizationPage;
use SpeedPuzzling\Web\Results\Organizations\OrganizationSeriesCard;
use SpeedPuzzling\Web\Services\EventsPage\EventRowFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Services\Organizations\OrganizationPageBuilder;
use SpeedPuzzling\Web\Tests\Services\EventDetail\PathUrlGenerator;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\OccurrenceRound;
use SpeedPuzzling\Web\Value\OrganizationKind;

/**
 * The organization page's rules (docs/features/organizations/README.md "Organization page"): "Coming up" (live, by
 * month, ongoing, without a date), the series cards and their next date, the one-time event cards, the past by year,
 * the header's place and star. Synthetic occurrences, a fixed "now".
 */
final class OrganizationPageBuilderTest extends TestCase
{
    // Wednesday
    private const string NOW = '2026-06-10 12:00:00';
    private const string SERIES_NIGHTS = 's-nights';
    private const string SERIES_ONLINE = 's-online';

    private int $ids = 0;

    public function testComingUpIsLiveThenByMonthThenOngoingThenWithoutADate(): void
    {
        $live = $this->edition(self::SERIES_NIGHTS, 'Night 1', '2026-06-10');
        $july = $this->edition(self::SERIES_NIGHTS, 'Night 2', '2026-07-06');
        $june = $this->event('Summer Open', '2026-06-20', '2026-06-21');
        $august = $this->edition(self::SERIES_ONLINE, 'Contest 3', '2026-08-19', online: true);
        $long = $this->event('All Year Challenge', '2026-01-01', '2026-12-31', online: true);
        $notSet = $this->edition(self::SERIES_NIGHTS, 'Night to be planned', null);
        $tba = $this->event('Winter Open', null);

        $page = $this->build([$live, $june, $july, $august, $long, $notSet, $tba]);

        self::assertSame(['Lantern Nights'], self::titles($page->live));
        self::assertSame([[2026, 6], [2026, 7], [2026, 8]], array_map(static fn ($month): array => [$month->year, $month->month], $page->comingUp));
        self::assertSame(['Summer Open'], self::titles($page->comingUp[0]->rows));
        self::assertSame(['All Year Challenge'], self::titles($page->ongoing));
        self::assertSame(['Lantern Nights', 'Winter Open'], self::titles($page->dateNotSet), 'by title');
        self::assertSame(7, $page->comingCount());

        // Rows are named as on the events page: the series, its edition under it; "Recurring" kept, no star
        $row = $page->comingUp[1]->rows[0];
        self::assertSame('Lantern Nights', $row->title);
        self::assertSame('Night 2', $row->editionName);
        self::assertSame([RowTagType::Recurring, RowTagType::Eligibility], self::tagTypes($row->tags));
        self::assertSame('18+', $row->tags[1]->text);
        self::assertNull($row->followTarget);
        self::assertSame('/series/lantern-nights/night-2', $row->url);
    }

    public function testARowGetsTheStartTimeOfItsFirstRound(): void
    {
        $round = new OccurrenceRound(
            id: 'r-1',
            name: 'Main round',
            startsAt: new DateTimeImmutable('2026-07-06 19:00', new DateTimeZone('America/New_York'))->setTimezone(new DateTimeZone('UTC')),
            zone: 'America/New_York',
            hasResults: false,
        );
        $dates = OccurrenceDates::sessions(null, null, [$round])[0];
        $edition = new EventOccurrence(
            competitionId: 'c-round',
            name: 'Night 1',
            slug: 'night-1',
            seriesId: self::SERIES_NIGHTS,
            seriesName: 'Lantern Nights',
            seriesSlug: 'lantern-nights',
            countryCode: CountryCode::us,
            startDate: $dates->start,
            endDate: $dates->end,
            roundCount: 1,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
        );

        $page = $this->build([$edition]);

        self::assertNotNull($page->comingUp[0]->rows[0]->time);
    }

    public function testThePastIsOneLinePerOccurrenceByYearNewestFirst(): void
    {
        $old = $this->edition(self::SERIES_ONLINE, 'Contest 1', '2025-06-17', online: true);
        $recent = $this->edition(self::SERIES_ONLINE, 'Contest 2', '2026-05-20', online: true);
        $openPast = $this->event('Spring Open', '2026-03-14', '2026-03-15');

        $page = $this->build([$old, $openPast, $recent]);

        self::assertSame([2026, 2025], array_map(static fn ($year): int => $year->year, $page->pastYears));
        self::assertSame(['Riverbend Contest', 'Spring Open'], array_map(static fn ($line): string => $line->title, $page->pastYears[0]->lines));
        self::assertSame('Contest 2', $page->pastYears[0]->lines[0]->editionName);
        self::assertSame(3, $page->pastCount());
        self::assertSame([], $page->eventCards, 'a past one-time event has no card');
    }

    public function testSeriesCardsShowTheNextDateElseTheLastElseNone(): void
    {
        $series = [
            $this->series(self::SERIES_NIGHTS, 'Lantern Nights', eligibility: '18+', schedule: 'Second Thursday of the month, 7:30 pm'),
            $this->series(self::SERIES_ONLINE, 'Riverbend Contest', online: true),
            $this->series('s-empty', 'Brand New Series'),
            $this->series('s-later', 'August Evenings'),
        ];
        $occurrences = [
            $this->edition(self::SERIES_NIGHTS, 'Night 1', '2026-05-04'),
            $this->edition(self::SERIES_NIGHTS, 'Night 2', '2026-07-06'),
            $this->edition(self::SERIES_NIGHTS, 'Night 3', '2026-08-03'),
            $this->edition(self::SERIES_ONLINE, 'Contest 1', '2026-04-15', online: true),
            $this->edition('s-later', 'Evening 1', '2026-08-01'),
        ];

        $page = $this->build($occurrences, $series);

        self::assertSame(['Lantern Nights', 'August Evenings', 'Riverbend Contest', 'Brand New Series'], self::cardNames($page->seriesCards), 'next by date, then the last one, then none');

        $nights = $page->seriesCards[0];
        self::assertSame(SeriesNext::NEXT, $nights->next->type);
        self::assertSame('2026-07-06', $nights->next->date?->format('Y-m-d'));
        self::assertSame(3, $nights->editionCount);
        self::assertSame('18+', $nights->eligibility);
        self::assertSame('Second Thursday of the month, 7:30 pm', $nights->schedule);
        self::assertSame('series:' . self::SERIES_NIGHTS, $nights->followTarget?->toString());
        self::assertSame('/series/lantern-nights', $nights->url);
        self::assertSame('Riverbend', $nights->place->city);

        self::assertSame(SeriesNext::LAST, $page->seriesCards[2]->next->type);
        self::assertTrue($page->seriesCards[2]->place->isOnline);
        self::assertSame(SeriesNext::NONE, $page->seriesCards[3]->next->type);
        self::assertSame(0, $page->seriesCards[3]->editionCount);
    }

    public function testSessionsOfOneEditionCountOnce(): void
    {
        $rounds = [];

        foreach (['2026-07-01', '2026-08-05'] as $number => $day) {
            $rounds[] = new OccurrenceRound(
                id: 'r-' . $number,
                name: 'Round ' . ($number + 1),
                startsAt: new DateTimeImmutable($day . ' 18:00', new DateTimeZone('UTC')),
                zone: 'UTC',
                hasResults: false,
            );
        }

        $sessions = array_map(static fn (OccurrenceDates $dates): EventOccurrence => new EventOccurrence(
            competitionId: 'c-sessions',
            name: 'Season',
            slug: 'season',
            seriesId: self::SERIES_NIGHTS,
            seriesName: 'Lantern Nights',
            seriesSlug: 'lantern-nights',
            startDate: $dates->start,
            endDate: $dates->end,
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
        ), OccurrenceDates::sessions(null, null, $rounds));

        $page = $this->build($sessions, [$this->series(self::SERIES_NIGHTS, 'Lantern Nights')]);

        self::assertSame(1, $page->seriesCards[0]->editionCount);
        self::assertSame(2, $page->comingCount(), 'each session is a row');
    }

    public function testTheTeamsDraftAndPendingSeriesAreTaggedWithoutAStar(): void
    {
        $page = $this->build([], [
            $this->series('s-draft', 'Draft Series', public: false, draft: true),
            $this->series('s-pending', 'Pending Series', public: false),
        ]);

        [$draft, $pending] = $page->seriesCards;
        self::assertTrue($draft->isDraft);
        self::assertFalse($draft->isPending, 'a draft says Draft, never "Waiting for approval"');
        self::assertNull($draft->followTarget);
        self::assertTrue($pending->isPending);
        self::assertNull($pending->followTarget);
    }

    public function testEventCardsAreTheOneTimeEventsNotOverYetOneCardPerEvent(): void
    {
        $rounds = [];

        foreach (['2026-06-01', '2026-07-12', '2026-07-13'] as $number => $day) {
            $rounds[] = new OccurrenceRound(
                id: 'open-r' . $number,
                name: 'Round ' . ($number + 1),
                startsAt: new DateTimeImmutable($day . ' 10:00', new DateTimeZone('UTC')),
                zone: 'UTC',
                hasResults: false,
            );
        }

        // Rounds on separate days: a session already over and the coming one - one card from the coming session on
        $openSessions = array_map(fn (OccurrenceDates $dates): EventOccurrence => new EventOccurrence(
            competitionId: 'c-open',
            name: 'Riverbend Open',
            slug: 'riverbend-open',
            location: 'Riverbend',
            countryCode: CountryCode::us,
            startDate: $dates->start,
            endDate: $dates->end,
            session: $dates->session,
            lastRoundDay: $dates->lastRoundDay,
            firstRound: $dates->firstRound,
            eligibility: 'Residents of Riverbend Valley',
        ), OccurrenceDates::sessions(null, null, $rounds));
        $undated = $this->event('Winter Open', null);
        $pending = $this->event('Pending Cup', '2026-06-20', public: false);
        $draft = $this->event('Draft Night', '2026-06-25', public: false, draft: true);
        $live = $this->event('Today Meetup', '2026-06-10');
        $edition = $this->edition(self::SERIES_NIGHTS, 'Night 2', '2026-06-15');

        $viewer = new EventsViewerData(followedCompetitionIds: ['c-open']);
        $page = $this->build([...$openSessions, $undated, $pending, $draft, $live, $edition], viewer: $viewer);

        self::assertSame(['Today Meetup', 'Pending Cup', 'Draft Night', 'Riverbend Open', 'Winter Open'], self::cardNames($page->eventCards), 'by date, undated last; no edition');

        $open = $page->eventCards[3];
        self::assertSame('2026-07-12', $open->from?->format('Y-m-d'), 'its first day still to come');
        self::assertSame('2026-07-13', $open->to?->format('Y-m-d'));
        self::assertSame(EventOccurrenceStatus::Upcoming, $open->status);
        self::assertSame('Residents of Riverbend Valley', $open->eligibility);
        self::assertSame('/events/riverbend-open', $open->url, 'the event page, not a session');
        self::assertTrue($open->following);
        self::assertSame('competition:c-open', $open->followTarget?->toString());

        self::assertTrue($page->eventCards[0]->isLive());
        self::assertNull($page->eventCards[0]->to);
        self::assertTrue($page->eventCards[1]->isPending);
        self::assertNull($page->eventCards[1]->followTarget);
        self::assertTrue($page->eventCards[2]->isDraft);
        self::assertFalse($page->eventCards[2]->isPending);
        self::assertSame(EventOccurrenceStatus::Tba, $page->eventCards[4]->status);
        self::assertNull($page->eventCards[4]->from);
    }

    public function testTheHeaderStarAndPlace(): void
    {
        $viewer = new EventsViewerData(followedOrganizationIds: ['o-1']);

        $public = $this->build([], viewer: $viewer);
        self::assertSame('organization:o-1', $public->followTarget?->toString());
        self::assertTrue($public->following);
        self::assertSame('Riverbend Valley', $public->place?->city);
        self::assertSame('United States', $public->place->country);
        self::assertSame(CountryCode::us, $public->place->countryCode);

        $pending = $this->build([], viewer: $viewer, organization: $this->organization(approved: false));
        self::assertNull($pending->followTarget, 'only a publicly visible organization can be followed');
        self::assertFalse($pending->following);

        $draft = $this->build([], organization: $this->organization(draft: true));
        self::assertNull($draft->followTarget);

        $nowhere = $this->build([], organization: $this->organization(region: null, country: null));
        self::assertNull($nowhere->place);
        self::assertTrue($nowhere->isEmpty());
    }

    public function testNextOf(): void
    {
        $day = OccurrenceDates::today(self::now());

        self::assertSame(SeriesNext::NONE, OrganizationPageBuilder::nextOf([], $day)->type);
        self::assertSame(SeriesNext::LIVE, OrganizationPageBuilder::nextOf([$this->event('Today', '2026-06-10')], $day)->type);
        self::assertSame(SeriesNext::ONGOING, OrganizationPageBuilder::nextOf([$this->event('All year', '2026-01-01', '2026-12-31')], $day)->type);

        $next = OrganizationPageBuilder::nextOf([$this->event('Later', '2026-09-01'), $this->event('Sooner', '2026-07-01'), $this->event('Today', '2026-06-10')], $day);
        self::assertSame(SeriesNext::NEXT, $next->type, 'an upcoming one wins over one live now');
        self::assertSame('2026-07-01', $next->date?->format('Y-m-d'));

        $last = OrganizationPageBuilder::nextOf([$this->event('Old', '2025-01-01'), $this->event('Newer', '2026-02-01')], $day);
        self::assertSame(SeriesNext::LAST, $last->type);
        self::assertSame('2026-02-01', $last->date?->format('Y-m-d'));
    }

    /**
     * @param list<EventOccurrence> $occurrences
     * @param list<EventSeriesRow> $series
     */
    private function build(array $occurrences, array $series = [], null|EventsViewerData $viewer = null, null|OrganizationDetail $organization = null): OrganizationPage
    {
        usort($occurrences, static fn (EventOccurrence $a, EventOccurrence $b): int => [$a->startDate === null, $a->startDate, $a->name] <=> [$b->startDate === null, $b->startDate, $b->name]);

        $urls = new EventUrls(new PathUrlGenerator());

        return new OrganizationPageBuilder(new EventRowFactory($urls), $urls)->build(
            $organization ?? $this->organization(),
            $occurrences,
            $series,
            [],
            $viewer,
            self::now(),
            'en',
        );
    }

    private function organization(bool $approved = true, bool $draft = false, null|string $region = 'Riverbend Valley', null|CountryCode $country = CountryCode::us): OrganizationDetail
    {
        return new OrganizationDetail(
            id: 'o-1',
            name: 'Riverbend Jigsaw Association',
            shortName: 'RJA',
            slug: 'riverbend-jigsaw-association',
            logo: null,
            about: null,
            website: null,
            socialLinks: [],
            countryCode: $country,
            region: $region,
            kind: OrganizationKind::Association,
            isDraft: $draft,
            approvedAt: $approved ? self::now() : null,
            rejectedAt: null,
            rejectionReason: null,
            addedByPlayerId: null,
            addedByPlayerName: null,
            createdAt: self::now(),
        );
    }

    private function series(string $id, string $name, bool $online = false, bool $public = true, bool $draft = false, null|string $eligibility = null, null|string $schedule = null): EventSeriesRow
    {
        return new EventSeriesRow(
            id: $id,
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            isOnline: $online,
            location: $online ? null : 'Riverbend',
            countryCode: CountryCode::us,
            isPublic: $public,
            eligibility: $eligibility,
            schedule: $schedule,
            isDraft: $draft,
        );
    }

    private function edition(string $seriesId, string $name, null|string $from, bool $online = false): EventOccurrence
    {
        return new EventOccurrence(
            competitionId: 'c-' . (++$this->ids),
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            seriesId: $seriesId,
            seriesName: $seriesId === self::SERIES_NIGHTS ? 'Lantern Nights' : ($seriesId === self::SERIES_ONLINE ? 'Riverbend Contest' : 'August Evenings'),
            seriesSlug: $seriesId === self::SERIES_NIGHTS ? 'lantern-nights' : ($seriesId === self::SERIES_ONLINE ? 'riverbend-contest' : 'august-evenings'),
            location: $online ? null : 'Riverbend',
            countryCode: CountryCode::us,
            isOnline: $online,
            startDate: $from !== null ? new DateTimeImmutable($from, new DateTimeZone('UTC')) : null,
            endDate: $from !== null ? new DateTimeImmutable($from, new DateTimeZone('UTC')) : null,
            eligibility: $seriesId === self::SERIES_NIGHTS ? '18+' : null,
        );
    }

    private function event(string $name, null|string $from, null|string $to = null, bool $online = false, bool $public = true, bool $draft = false): EventOccurrence
    {
        return new EventOccurrence(
            competitionId: 'c-' . (++$this->ids),
            name: $name,
            slug: strtolower(str_replace(' ', '-', $name)),
            location: $online ? null : 'Riverbend',
            countryCode: CountryCode::us,
            isOnline: $online,
            startDate: $from !== null ? new DateTimeImmutable($from, new DateTimeZone('UTC')) : null,
            endDate: $from !== null ? new DateTimeImmutable($to ?? $from, new DateTimeZone('UTC')) : null,
            isPublic: $public,
            isDraft: $draft,
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
     * @param list<OrganizationSeriesCard>|list<OrganizationEventCard> $cards
     *
     * @return list<string>
     */
    private static function cardNames(array $cards): array
    {
        return array_map(static fn (OrganizationSeriesCard|OrganizationEventCard $card): string => $card->name, $cards);
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
}
