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
use SpeedPuzzling\Web\Services\EventsPage\EventsIndexFactory;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageBuilder;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\FollowTarget;
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

        self::assertSame(['id', 'k', 'n', 'en', 'sid', 'u', 'f', 't', 'lr', 'sc', 'c', 'p', 'st', 'r', 'w', 'x'], array_keys($entry));
        self::assertSame(7, $entry['id']);
        self::assertSame('s', $entry['k']);
        self::assertNull($entry['st']);
        self::assertNull($entry['f']);
        self::assertSame('at', $entry['sc']);
        self::assertSame('Innsbruck, Austria', $entry['p']);
        self::assertSame('summit puzzle league innsbruck austria', $entry['x']);
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
