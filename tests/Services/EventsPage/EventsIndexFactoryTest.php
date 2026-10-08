<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventsPage;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventSeriesRow;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\SeriesLine;
use SpeedPuzzling\Web\Results\EventsPage\SeriesNext;
use SpeedPuzzling\Web\Results\OrganizationRef;
use SpeedPuzzling\Web\Services\EventsPage\EventsIndexFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Services\EventsPage\EventUrls;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceSession;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RequestContext;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The format of the events page index (docs/features/events-page/implementation-plan.md, 1.5) - assets/events_index.js
 * reads exactly these keys.
 */
final class EventsIndexFactoryTest extends TestCase
{
    public function testAnEditionEntry(): void
    {
        $edition = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000001',
            name: 'Session 3',
            slug: 'session-3',
            seriesId: '018d0099-0000-0000-0000-000000000002',
            seriesName: 'Harbor Jigsaw Nights',
            seriesSlug: 'harbor-jigsaw-nights',
            location: 'Online',
            countryCode: CountryCode::ca,
            isOnline: true,
            startDate: new DateTimeImmutable('2026-12-05', new DateTimeZone('UTC')),
            isPublic: false,
        );

        $entry = $this->factory()->occurrence(12, $edition, EventOccurrenceStatus::Upcoming, '/en/series/harbor-jigsaw-nights/session-3', EventsPageBuilder::place(true, 'Online', CountryCode::ca, 'de'), 40, 'de');

        self::assertSame([
            'id' => 12,
            'k' => 'd',
            'n' => 'Harbor Jigsaw Nights',
            'en' => 'Session 3',
            'sl' => null,
            'cm' => '018d0099-0000-0000-0000-000000000001',
            'sid' => 40,
            'u' => '/en/series/harbor-jigsaw-nights/session-3',
            'f' => '2026-12-05',
            't' => null,
            'lr' => false,
            'sc' => 'online',
            'c' => 'ca',
            'p' => 'Online',
            'st' => 'upcoming',
            'r' => false,
            'w' => true,
            // names, location, the country in German and in English, the year, "online"
            'x' => 'session 3 harbor jigsaw nights online kanada canada 2026',
        ], $entry);
    }

    public function testAOneTimeEventEntryIsFolded(): void
    {
        $event = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000003',
            name: 'Ærø Straße Puzzle Cup',
            slug: 'aero-cup',
            location: 'Søby',
            countryCode: CountryCode::dk,
            startDate: new DateTimeImmutable('2025-06-01', new DateTimeZone('UTC')),
            endDate: new DateTimeImmutable('2025-06-30', new DateTimeZone('UTC')),
            hasResults: true,
        );

        $entry = $this->factory()->occurrence(3, $event, EventOccurrenceStatus::Past, '/en/events/aero-cup', EventsPageBuilder::place(false, 'Søby', CountryCode::dk, 'cs'), null, 'cs');

        self::assertSame('e', $entry['k']);
        self::assertNull($entry['en']);
        self::assertNull($entry['sid']);
        self::assertSame('2025-06-30', $entry['t']);
        self::assertTrue($entry['lr'], 'over 14 days');
        self::assertSame('dk', $entry['sc']);
        self::assertSame('Søby, Dánsko', $entry['p']);
        self::assertSame('past', $entry['st']);
        self::assertTrue($entry['r']);
        self::assertSame('aero strasse puzzle cup soby dansko denmark 2025', $entry['x']);
    }

    public function testEverySessionOfACompetitionIsItsOwnEntryWithItsOwnId(): void
    {
        $competitionId = '018d0099-0000-0000-0000-000000000005';
        $session = static fn (string $day, int $index, string $roundId, string $label): EventOccurrence => new EventOccurrence(
            competitionId: $competitionId,
            name: 'Night Owl Rounds',
            slug: 'night-owl-rounds',
            seriesId: '018d0099-0000-0000-0000-000000000006',
            seriesName: 'Lantern Puzzle Club',
            seriesSlug: 'lantern-puzzle-club',
            countryCode: CountryCode::us,
            isOnline: true,
            startDate: new DateTimeImmutable($day, new DateTimeZone('UTC')),
            roundCount: 2,
            session: new OccurrenceSession($index, 2, $roundId, $label),
        );

        $september = $session('2026-09-08', 0, 'round-a', 'September sprint');
        $october = $session('2026-10-27', 1, 'round-b', 'October sprint');

        $entry = $this->factory()->occurrence(4, $october, EventOccurrenceStatus::Upcoming, '/s#round-round-b', EventsPageBuilder::place(true, null, CountryCode::us, 'en'), null, 'en');
        self::assertSame(['Night Owl Rounds', 'October sprint', $competitionId], [$entry['en'], $entry['sl'], $entry['cm']]);
        self::assertSame('night owl rounds lantern puzzle club october sprint united states united states of america 2026 online', $entry['x']);

        // Through the builder: two entries, two ids, the session's link and status each
        $urlGenerator = new class implements UrlGeneratorInterface {
            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return '/' . $name;
            }

            public function setContext(RequestContext $context): void
            {
            }

            public function getContext(): RequestContext
            {
                return new RequestContext();
            }
        };

        $page = new EventsPageBuilder(new EventUrls($urlGenerator), $this->factory())->build(
            [$september, $october],
            [new EventSeriesRow('018d0099-0000-0000-0000-000000000006', 'Lantern Puzzle Club', 'lantern-puzzle-club', true, null, CountryCode::us)],
            [],
            null,
            EventsScope::everywhere(),
            new DateTimeImmutable('2026-10-07 10:00', new DateTimeZone('UTC')),
            'en',
            null,
        );

        $sessions = array_values(array_filter($page->index, static fn (array $entry): bool => $entry['k'] === 'd'));
        self::assertSame([0, 1], array_column($sessions, 'id'));
        self::assertSame(['past', 'upcoming'], array_column($sessions, 'st'));
        self::assertSame(['/edition_detail#round-round-a', '/edition_detail#round-round-b'], array_column($sessions, 'u'));
        self::assertSame([0], $page->archiveYears[0]->lines[0]->indexIds);
        self::assertSame([1], $page->months[0]->rows[0]->indexIds);
    }

    public function testASeriesEntry(): void
    {
        $series = new EventSeriesRow('018d0099-0000-0000-0000-000000000004', 'Summit Puzzle League', 'summit-puzzle-league', false, 'Innsbruck', CountryCode::at);
        $line = new SeriesLine(
            indexId: 7,
            seriesId: $series->id,
            name: $series->name,
            url: '/en/series/summit-puzzle-league',
            place: EventsPageBuilder::place(false, 'Innsbruck', CountryCode::at, 'en'),
            isOnline: false,
            editionCount: 0,
            next: new SeriesNext(SeriesNext::NONE, null),
            followTarget: FollowTarget::series($series->id),
            following: false,
            manage: new ManageRef(ManageRef::KIND_SERIES, $series->id, $series->name),
            isPending: false,
            scopeKey: 'at',
            visible: true,
        );

        $entry = $this->factory()->series($line, $series, 'en');

        self::assertSame(['id', 'k', 'n', 'en', 'sl', 'cm', 'sid', 'u', 'f', 't', 'lr', 'sc', 'c', 'p', 'st', 'r', 'w', 'x'], array_keys($entry));
        self::assertSame(7, $entry['id']);
        self::assertSame('s', $entry['k']);
        self::assertNull($entry['st']);
        self::assertNull($entry['f']);
        self::assertSame('at', $entry['sc']);
        self::assertSame('Innsbruck, Austria', $entry['p']);
        self::assertSame('summit puzzle league innsbruck austria', $entry['x']);
    }

    public function testThePublicOrganizationsNameAndShortNameAreSearchable(): void
    {
        $organization = new OrganizationRef('018d0099-0000-0000-0000-000000000010', 'Riverbend Jigsaw Association', 'RJA', 'riverbend-jigsaw-association', true);
        $event = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000011',
            name: 'Spring Open',
            slug: 'spring-open',
            location: 'Riverbend',
            countryCode: CountryCode::us,
            startDate: new DateTimeImmutable('2026-12-05', new DateTimeZone('UTC')),
            organization: $organization,
        );

        $entry = $this->factory()->occurrence(0, $event, EventOccurrenceStatus::Upcoming, '/en/events/spring-open', EventsPageBuilder::place(false, 'Riverbend', CountryCode::us, 'en'), null, 'en');

        self::assertSame('spring open riverbend united states united states of america 2026 riverbend jigsaw association rja', $entry['x']);

        $series = new EventSeriesRow('018d0099-0000-0000-0000-000000000012', 'Lantern Nights', 'lantern-nights', false, 'Riverbend', CountryCode::us, organization: $organization);
        $seriesEntry = $this->factory()->series($this->seriesLine($series, $organization), $series, 'en');

        self::assertSame('lantern nights riverbend united states united states of america riverbend jigsaw association rja', $seriesEntry['x']);
    }

    public function testADraftOrPendingOrganizationIsNotSearchable(): void
    {
        $organization = new OrganizationRef('018d0099-0000-0000-0000-000000000013', 'Harbor Puzzle Club', null, 'harbor-puzzle-club', false);
        $event = new EventOccurrence(
            competitionId: '018d0099-0000-0000-0000-000000000014',
            name: 'Club Meet',
            slug: 'club-meet',
            isOnline: true,
            startDate: new DateTimeImmutable('2026-12-05', new DateTimeZone('UTC')),
            organization: $organization,
        );

        $entry = $this->factory()->occurrence(0, $event, EventOccurrenceStatus::Upcoming, '/en/events/club-meet', EventsPageBuilder::place(true, null, null, 'en'), null, 'en');

        self::assertSame('club meet 2026 online', $entry['x']);

        // A series line carries only a publicly visible organization (EventsPageBuilder) - nothing to fold
        $series = new EventSeriesRow('018d0099-0000-0000-0000-000000000015', 'Club Meets', 'club-meets', true, organization: $organization);
        self::assertSame('club meets online', $this->factory()->series($this->seriesLine($series, null), $series, 'en')['x']);
    }

    private function seriesLine(EventSeriesRow $series, null|OrganizationRef $organization): SeriesLine
    {
        return new SeriesLine(
            indexId: 3,
            seriesId: $series->id,
            name: $series->name,
            url: '/en/series/' . $series->slug,
            place: EventsPageBuilder::place($series->isOnline, $series->location, $series->countryCode, 'en'),
            isOnline: $series->isOnline,
            editionCount: 0,
            next: new SeriesNext(SeriesNext::NONE, null),
            followTarget: FollowTarget::series($series->id),
            following: false,
            manage: new ManageRef(ManageRef::KIND_SERIES, $series->id, $series->name),
            isPending: false,
            scopeKey: $series->isOnline ? 'online' : 'us',
            visible: true,
            organization: $organization,
        );
    }

    private function factory(): EventsIndexFactory
    {
        return new EventsIndexFactory(new class implements TranslatorInterface {
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
        });
    }
}
