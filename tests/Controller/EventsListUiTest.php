<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The list page markup (docs/features/events-page/implementation-plan.md, workstream A): what the server renders for
 * each scope and search, so the page is right before - and without - JavaScript.
 */
final class EventsListUiTest extends WebTestCase
{
    public function testSummaryLine(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');

        self::assertMatchesRegularExpression(
            '/^\d+ upcoming dates in \d+ countries and online · \d+ series$/',
            trim($crawler->filter('.ev-summary')->text()),
        );
    }

    public function testSeveralEditionsOfASeriesInOneMonthAreOneRowWithAChipPerSession(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');
        $harbor = $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME);

        self::assertCount(1, $harbor);
        self::assertStringContainsString('ev-row-group', (string) $harbor->attr('class'));
        self::assertCount(3, explode(' ', (string) $harbor->attr('data-ev-ids')));
        self::assertCount(3, $harbor->filter('a.ev-session'));
        self::assertStringContainsString('3 sessions', $harbor->filter('.ev-sessions-n')->text());
        self::assertSame('online', $harbor->attr('data-ev-scope'));
    }

    public function testALongRunningEditionWithoutRoundsIsOngoingNotLive(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');

        self::assertCount(0, $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_CLOCK_MARATHON_NAME), 'neither Live nor in a month');

        $clock = $this->rows($crawler, '[data-ev-series-group="ongoing"] .ev-ongoing-line', EventsPageFixture::SERIES_CLOCK_MARATHON_NAME);
        self::assertCount(1, $clock);
        self::assertStringStartsWith('Runs until', $clock->filter('.ev-tag-runs_until')->text());
        self::assertStringContainsString('Lakeside', $clock->filter('.ev-series-sub')->text(), 'its place, not "no fixed dates"');
        self::assertStringStartsWith('Ongoing ', trim($crawler->filter('[data-ev-series-group="ongoing"] .ev-subhead')->text()));

        $series = $this->rows($crawler, '[data-ev-series-group="in_person"] .ev-series-line', EventsPageFixture::SERIES_CLOCK_MARATHON_NAME);
        self::assertSame('Ongoing', $series->filter('.ev-series-next')->text());
    }

    /**
     * An edition with a round a month: between two rounds it is not live - the agenda shows its next session
     */
    public function testAnEditionWithMonthlyRoundsShowsItsNextSessionNotLive(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);
        $roundDays = EventsPageFixture::storedSprintRoundDays($connection);
        // The day the fixtures were built, so "In 25 days" holds whenever the test runs
        self::getContainer()->set(ClockInterface::class, new MockClock(EventsPageFixture::builtOn($connection)->setTime(12, 0)));
        $crawler = $this->page($browser, '/en/events');

        $sprint = $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_SPRINT_LEAGUE_NAME);
        self::assertCount(2, $sprint, 'the two coming sessions, each in its month');
        self::assertCount(0, $this->rows($crawler, '[data-ev-group="now"] .ev-row', EventsPageFixture::SERIES_SPRINT_LEAGUE_NAME));

        $next = $sprint->first();
        self::assertSame(EventsPageFixture::EDITION_SPRINT_SEASON_NAME . ' · Sprint 3', $next->filter('.ev-row-edition')->text());
        self::assertStringEndsWith('/season-one#round-' . EventsPageFixture::ROUND_SPRINT_3, (string) $next->filter('a.ev-row-name')->attr('href'));
        self::assertSame('In 25 days', $next->filter('.ev-when')->text());
        self::assertSame($roundDays[2], $next->attr('data-ev-from'));

        $line = $this->rows($crawler, '[data-ev-series-group="online"] .ev-series-line', EventsPageFixture::SERIES_SPRINT_LEAGUE_NAME);
        self::assertStringContainsString('1 edition', $line->text());
        self::assertStringStartsWith('Next: ', $line->filter('.ev-series-next')->text());
    }

    /**
     * On a session's day it is Live: the "Live" header and label with the pulsing dot, hidden from screen readers
     */
    public function testASessionOnItsDayIsLiveWithThePulsingDot(): void
    {
        $browser = self::createClient();
        // The third round's day in New York, 18:00 UTC
        $day = EventsPageFixture::storedSprintRoundDays(self::getContainer()->get(Connection::class))[2];
        self::getContainer()->set(ClockInterface::class, new MockClock(new DateTimeImmutable($day . ' 18:00', new DateTimeZone('UTC'))));
        $crawler = $this->page($browser, '/en/events');

        $header = $crawler->filter('[data-ev-group="now"] .ev-month-header');
        self::assertCount(1, $header);
        self::assertStringContainsString('Live', $header->text());
        self::assertCount(1, $header->filter('.live-dot[aria-hidden="true"]'));

        $sprint = $this->rows($crawler, '[data-ev-group="now"] .ev-row', EventsPageFixture::SERIES_SPRINT_LEAGUE_NAME);
        self::assertCount(1, $sprint);
        self::assertSame('Live', $sprint->filter('.ev-when')->text());
        self::assertCount(1, $sprint->filter('.ev-when .live-dot[aria-hidden="true"]'));
        self::assertStringContainsString('Sprint 3', $sprint->filter('.ev-row-edition')->text());
    }

    public function testAnEventWithoutADateIsToBeAnnouncedWithItsRegistrationLink(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');
        $meadow = $this->rows($crawler, '[data-ev-group="tba"] .ev-row', EventsPageFixture::COMPETITION_MEADOW_TBA_NAME);

        self::assertCount(1, $meadow);
        self::assertSame('Registration', $meadow->filter('.ev-tag-registration')->text());
        self::assertSame('TBA', $meadow->filter('.ev-leaf-band')->text());
        self::assertStringContainsString('Date to be announced', $crawler->filter('[data-ev-group="tba"] .ev-month-header')->text());
    }

    public function testAFullEventShowsTheWaitlistAndHowManyAreGoing(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');
        $riverside = $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME);

        self::assertCount(1, $riverside);
        self::assertSame('Full · waitlist', $riverside->filter('.ev-tag-full_waitlist')->text());
        self::assertSame('2 going', $riverside->filter('.ev-tag-going_count')->text());
        self::assertCount(0, $riverside->filter('.ev-tag-registration_open'));
        self::assertStringContainsString('Hamburg', $riverside->filter('.ev-place-city')->text());
        self::assertStringContainsString('Germany', $riverside->filter('.ev-place-country')->text());
        self::assertCount(1, $riverside->filter('.fi.fi-de[aria-hidden="true"]'));
    }

    public function testSeriesDirectoryAndOngoingOnline(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');

        $relay = $this->rows($crawler, '[data-ev-series-group="ongoing"] .ev-ongoing-line', EventsPageFixture::COMPETITION_ENDLESS_RELAY_NAME);
        self::assertCount(1, $relay);
        self::assertCount(0, $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::COMPETITION_ENDLESS_RELAY_NAME));

        $summit = $this->rows($crawler, '[data-ev-series-group="in_person"] .ev-series-line', EventsPageFixture::SERIES_SUMMIT_LEAGUE_NAME);
        self::assertCount(1, $summit);
        self::assertSame('No dates yet', $summit->filter('.ev-series-next')->text());

        $harbor = $this->rows($crawler, '[data-ev-series-group="online"] .ev-series-line', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME);
        self::assertCount(1, $harbor);
        self::assertStringStartsWith('Next: ', $harbor->filter('.ev-series-next')->text());

        self::assertSelectorTextNotContains('main', EventsPageFixture::SERIES_OLD_MILL_REJECTED_NAME);
        self::assertSelectorTextNotContains('main', EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED_NAME);
    }

    public function testOnlineEventsAreUnderOnlineNeverUnderTheirCountry(): void
    {
        $browser = self::createClient();

        $crawler = $this->page($browser, '/en/events?country=ca');
        $harbor = $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME);
        self::assertCount(1, $harbor);
        self::assertNotNull($harbor->attr('hidden'));

        $crawler = $this->page($browser, '/en/events?onlineOnly=1');
        $harbor = $this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME);
        self::assertNull($harbor->attr('hidden'));
        self::assertNotNull($this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME)->attr('hidden'));
        self::assertSame('true', $crawler->filter('.ev-chips [data-ev-scope="online"]')->attr('aria-current'));
        self::assertSame('Upcoming · Online', trim($crawler->filter('[data-ev-agenda-title]')->text()));
        self::assertStringStartsWith('Online · ', trim($crawler->filter('.ev-month:not([hidden]) .ev-month-header')->first()->text()));
        self::assertStringStartsWith('Past · Online', trim($crawler->filter('.ev-archive .ev-block-title')->text()));
    }

    public function testYourEventsOrderedByDateUndatedLastWithTheirMarks(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->page($browser, '/en/events');

        $cards = $crawler->filter('.ev-your-events .ev-your-event');
        self::assertGreaterThanOrEqual(3, $cards->count());
        self::assertCount(0, $crawler->filter('.ev-your-events .ev-row'), 'cards are never .ev-row - the calendar clones those');

        $names = $cards->each(static fn (Crawler $card): string => $card->filter('.ev-your-name')->text());
        $harborAt = array_search(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $names, true);
        self::assertIsInt($harborAt);
        self::assertSame(EventsPageFixture::COMPETITION_MEADOW_TBA_NAME, end($names), 'undated last');
        self::assertLessThan(count($names) - 1, $harborAt);

        self::assertCount(1, $cards->eq($harborAt)->filter('.ev-mark-following'));
        self::assertSame('Session 1', $cards->eq($harborAt)->filter('.ev-your-edition')->text());
        self::assertCount(1, $cards->last()->filter('.ev-mark-following'));
        self::assertGreaterThan(0, $crawler->filter('.ev-your-events .ev-mark-going')->count());

        // Not in a country view
        $crawler = $this->page($browser, '/en/events?country=de');
        self::assertNotNull($crawler->filter('.ev-your-events')->attr('hidden'));
    }

    public function testAPlayerWhoseCountryHasNothingPlannedIsToldSo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->page($browser, '/en/events');
        $chip = $crawler->filter('.ev-chips .ev-chip-home');
        self::assertSame('gb', $chip->attr('data-ev-scope'));
        self::assertCount(0, $chip->filter('.ev-chip-n'), 'no count on an empty home chip');

        $callout = $crawler->filter('[data-ev-home-callout="gb"]');
        self::assertCount(1, $callout);
        self::assertNull($callout->attr('hidden'));
        self::assertStringContainsString('Nothing planned in United Kingdom yet.', $callout->text());

        // Its own view: the honest empty state and the callout
        $crawler = $this->page($browser, '/en/events?country=gb');
        self::assertNull($crawler->filter('[data-ev-empty]')->attr('hidden'));
        self::assertStringContainsString('No upcoming events in United Kingdom', $crawler->filter('[data-ev-empty]')->text());
        self::assertNull($crawler->filter('[data-ev-home-callout]')->attr('hidden'));

        // A player with something coming at home gets no callout
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->page($browser, '/en/events');
        self::assertCount(0, $crawler->filter('[data-ev-home-callout]'));
    }

    public function testGuestsGetAGuessChipToFillInTheBrowser(): void
    {
        $crawler = $this->page(self::createClient(), '/en/events');

        $guess = $crawler->filter('.ev-chips .ev-chip-guess');
        self::assertCount(1, $guess);
        self::assertNotNull($guess->attr('hidden'));
        self::assertSame('Guessed from your browser', $guess->attr('title'));
        self::assertCount(0, $crawler->filter('.ev-chips .ev-chip-home'));
    }

    public function testTheArchivePreviewShowsTheNewestYearsLatestLinesAndShowAll(): void
    {
        $browser = self::createClient();
        $connection = self::getContainer()->get(Connection::class);

        // All yesterday: the newest archive year is always theirs, on 1 January too
        for ($i = 1; $i <= 6; $i++) {
            $connection->executeStatement(
                "INSERT INTO competition (id, name, location, location_country_code, date_from, date_to, is_online, approved_at, created_at)
                 VALUES (:id, :name, 'Prague', 'cz', CURRENT_DATE - make_interval(days => :days), CURRENT_DATE - make_interval(days => :days), false, NOW(), NOW())",
                ['id' => Uuid::uuid7()->toString(), 'name' => 'Archive preview event ' . $i, 'days' => 1],
            );
        }

        $crawler = $this->page($browser, '/en/events');
        $newest = $crawler->filter('.ev-archive .ev-years .ev-year')->first();
        $year = (int) $newest->attr('data-ev-year');

        self::assertSame('true', $newest->attr('aria-current'));
        self::assertSame('/en/events/archive/' . $year, $newest->attr('href'));
        self::assertCount(5, $crawler->filter('.ev-archive .ev-archive-lines .ev-line'));

        $showAll = $crawler->filter('.ev-archive [data-ev-show-all]');
        self::assertCount(1, $showAll);
        self::assertMatchesRegularExpression('/^Show all ' . $year . ' \(\d+\)$/', trim($showAll->text()));
        self::assertSame('/en/events/archive/' . $year, $showAll->attr('href'));
    }

    public function testASearchRendersAlreadyFiltered(): void
    {
        $browser = self::createClient();
        $crawler = $this->page($browser, '/en/events?q=harbor');

        self::assertStringContainsString('is-searching', (string) $crawler->filter('.ev-page')->attr('class'));
        self::assertSame('harbor', $crawler->filter('#ev-search-input')->attr('value'));
        self::assertNull($this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME)->attr('hidden'));
        self::assertNotNull($this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN_NAME)->attr('hidden'));

        // Past editions one by one, with the year
        $past = $crawler->filter('.ev-search-past .ev-line');
        self::assertNull($crawler->filter('.ev-search-past')->attr('hidden'));
        self::assertCount(2, $past);
        self::assertMatchesRegularExpression('/\d{4}$/', trim($past->first()->filter('.ev-line-date')->text()));

        self::assertNotNull($crawler->filter('.ev-archive')->attr('hidden'));
        self::assertNotNull($crawler->filter('.ev-search-nothing')->attr('hidden'));

        // Typed with accents and in other case, every word must match
        $crawler = $this->page($browser, '/en/events?q=HÁRBOR+online');
        self::assertNull($this->rows($crawler, '.ev-agenda .ev-row', EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME)->attr('hidden'));

        $crawler = $this->page($browser, '/en/events?q=zzqqxx');
        self::assertNull($crawler->filter('.ev-search-nothing')->attr('hidden'));
        self::assertStringContainsString('Nothing found for “zzqqxx”', $crawler->filter('.ev-search-nothing')->text());
        self::assertNotNull($crawler->filter('.ev-agenda')->attr('hidden'));
    }

    public function testRowsCarryTheContractAttributesAndNoIds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->page($browser, '/en/events');

        $rows = $crawler->filter('.ev-agenda .ev-row');
        self::assertGreaterThan(5, $rows->count());

        $rows->each(static function (Crawler $row): void {
            foreach (['data-ev-ids', 'data-ev-scope', 'data-ev-from', 'data-ev-to', 'data-ev-status'] as $attribute) {
                self::assertNotNull($row->attr($attribute), $attribute);
            }

            self::assertMatchesRegularExpression('/^\d+( \d+)*$/', (string) $row->attr('data-ev-ids'));
            self::assertCount(0, $row->filter('[id]'), 'no id inside a row - the calendar clones rows');
        });

        self::assertCount(1, $crawler->filter('template[data-events-archive-line-template] '));
        self::assertGreaterThan(0, $crawler->filter('.ev-agenda .ev-row-pending .ev-tag-waiting_for_approval')->count());
    }

    public function testTheCountrySheetIsGroupedByRegionAndSearchable(): void
    {
        $crawler = $this->page(self::createClient(), '/de/veranstaltungen');

        $sheet = $crawler->filter('#ev-sheet');
        self::assertCount(1, $sheet);
        self::assertSame('dialog', $sheet->filter('.ev-sheet-panel')->attr('role'));
        self::assertGreaterThan(0, $sheet->filter('[data-ev-sheet-group] .ev-sheet-region')->count());

        // Localised and English name, folded: "deutschland germany"
        $germany = $sheet->filter('li[data-ev-search] a[data-ev-scope="de"]');
        self::assertCount(1, $germany);
        self::assertSame('deutschland germany', $germany->ancestors()->filter('li')->first()->attr('data-ev-search'));
    }

    private function page(KernelBrowser $browser, string $url): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful($url);

        return $crawler;
    }

    private function rows(Crawler $crawler, string $selector, string $name): Crawler
    {
        return $crawler->filter($selector)->reduce(static fn (Crawler $row): bool => str_contains($row->text(), $name));
    }
}
