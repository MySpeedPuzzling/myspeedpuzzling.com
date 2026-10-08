<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The calendar view and the desktop side calendar of the events page (docs/features/events-page/implementation-plan.md,
 * 2.B). The grid itself is drawn in the browser from the index (tests/EventsCalendarScriptTest.php); here the server's
 * part: which view is shown, the month, the texts the script needs and the calendar data in the index.
 */
final class EventsCalendarTest extends WebTestCase
{
    public function testTheCalendarViewShowsTheCalendarAndHidesTheList(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events?view=calendar');

        self::assertResponseIsSuccessful();

        $calendar = $crawler->filter('.ev-calendar[data-ev-calendar]');
        self::assertCount(1, $calendar);
        self::assertNull($calendar->attr('hidden'));
        self::assertSame('events-calendar', $calendar->attr('data-controller'));
        self::assertSame('full', $calendar->attr('data-events-calendar-mode-value'));
        self::assertSame('', $calendar->attr('data-events-calendar-month-value'));

        self::assertNotNull($crawler->filter('.ev-list-view')->attr('hidden'));
        // The side calendar belongs to the list view
        self::assertNotNull($crawler->filter('.ev-rail-calendar')->attr('hidden'));

        // Without JavaScript the list comes back and the calendar says why
        self::assertStringContainsString('.ev-list-view[hidden]', $calendar->filter('noscript')->html());
        self::assertStringContainsString('The calendar needs JavaScript', $calendar->filter('noscript')->html());
    }

    public function testTheListViewKeepsTheCalendarHiddenAndShowsTheSideCalendar(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events');

        self::assertResponseIsSuccessful();
        self::assertNotNull($crawler->filter('.ev-calendar[data-ev-calendar]')->attr('hidden'));
        self::assertNull($crawler->filter('.ev-list-view')->attr('hidden'));

        $rail = $crawler->filter('.ev-rail-calendar');
        self::assertCount(1, $rail);
        self::assertNull($rail->attr('hidden'));
        self::assertSame('rail', $rail->attr('data-events-calendar-mode-value'));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $rail->attr('data-events-calendar-today-value'));
    }

    public function testTheMonthComesFromTheUrl(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events?view=calendar&month=2025-11');

        self::assertResponseIsSuccessful();

        $calendar = $crawler->filter('.ev-calendar[data-ev-calendar]');
        self::assertSame('2025-11', $calendar->attr('data-events-calendar-month-value'));
        self::assertSame('November 2025', trim($calendar->filter('.ev-cal-title')->text()));

        // A month in the past is not "today's" month: the Today button works
        self::assertNull($calendar->filter('.ev-cal-today-btn')->attr('disabled'));

        $crawler = $browser->request('GET', '/en/events?view=calendar&month=2026-13');
        self::assertSame('', $crawler->filter('.ev-calendar[data-ev-calendar]')->attr('data-events-calendar-month-value'));
        self::assertNotNull($crawler->filter('.ev-cal-today-btn')->attr('disabled'));
    }

    public function testTheMonthNamesAreLocalised(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/de/veranstaltungen?view=calendar&month=2026-03');

        self::assertResponseIsSuccessful();
        self::assertSame('März 2026', trim($crawler->filter('.ev-cal-title')->text()));
    }

    public function testTheScriptGetsItsTextsWithTheirPluralForms(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events?view=calendar');

        /** @var array<string, mixed> $messages */
        $messages = json_decode((string) $crawler->filter('.ev-calendar[data-ev-calendar]')->attr('data-events-calendar-messages-value'), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['message' => '%day%: %count% event|%day%: %count% events', 'locale' => 'en'], $messages['dayLabel']);
        self::assertSame(['message' => '%count% date|%count% dates', 'locale' => 'en'], $messages['monthCount']);
        self::assertSame('%date% is highlighted', $messages['highlighted']);
        self::assertSame('+%count% more', $messages['more']);
        self::assertSame('Online', $messages['online']);
    }

    public function testTheIndexCarriesHarborsThreeSessionsWithTheirDates(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/events?view=calendar');

        $sessions = array_values(array_filter(
            self::index($crawler),
            static fn (array $entry): bool => $entry['n'] === EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME
                && $entry['k'] === 'd'
                && $entry['st'] === 'upcoming',
        ));

        self::assertCount(3, $sessions);

        $days = array_map(static fn (array $entry): string => (string) $entry['f'], $sessions);
        sort($days);

        // Days 5, 12 and 19 of one month - session 1 dated by its evening round in Toronto, not the next UTC day
        self::assertSame(['05', '12', '19'], array_map(static fn (string $day): string => substr($day, 8, 2), $days));
        self::assertCount(1, array_unique(array_map(static fn (string $day): string => substr($day, 0, 7), $days)));

        foreach ($sessions as $session) {
            self::assertSame('online', $session['sc']);
            self::assertFalse($session['lr']);
            self::assertNotNull($session['u']);
        }

        // The long-running Clock Marathon edition (no rounds: ongoing, not live) is in the index as one entry, flagged
        // for a bar
        $clock = array_values(array_filter(self::index($crawler), static fn (array $entry): bool => $entry['n'] === EventsPageFixture::SERIES_CLOCK_MARATHON_NAME && $entry['k'] === 'd'));
        self::assertCount(1, $clock);
        self::assertTrue($clock[0]['lr']);
        self::assertSame('ongoing', $clock[0]['st']);
    }

    public function testRowsTheCalendarClonesCarryTheirIds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/events?view=calendar');

        self::assertResponseIsSuccessful();

        $index = self::index($crawler);
        $harborIds = [];

        foreach ($index as $entry) {
            if ($entry['n'] === EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME && $entry['k'] === 'd' && $entry['st'] === 'upcoming') {
                $harborIds[] = $entry['id'];
            }
        }

        // Every session finds the one roll-up row of the agenda through `.ev-row[data-ev-ids~="N"]`
        foreach ($harborIds as $id) {
            $rows = $crawler->filter('.ev-list-view .ev-row')->reduce(static fn (Crawler $row): bool => in_array((string) $id, explode(' ', (string) $row->attr('data-ev-ids')), true));
            self::assertGreaterThan(0, $rows->count(), sprintf('a row for index entry %d', $id));
        }

        // The archive line template the calendar fills for past months
        self::assertCount(1, $crawler->filter('template[data-events-archive-line-template]'));
    }

    /**
     * @return list<array{id: int, k: string, n: string, f: null|string, sc: string, lr: bool, st: null|string, u: null|string}>
     */
    private static function index(Crawler $crawler): array
    {
        /** @var list<array{id: int, k: string, n: string, f: null|string, sc: string, lr: bool, st: null|string, u: null|string}> $index */
        $index = json_decode($crawler->filter('script[data-events-index]')->text(), true, flags: JSON_THROW_ON_ERROR);

        return $index;
    }
}
