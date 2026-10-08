<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The series page's markup (docs/features/events-page/detail-pages.md "Series page", detail-pages-plan.md workstream A):
 * the Next card, upcoming by month, Ongoing, Date not set, the past by year, the facts and the header's actions - what
 * the server renders, so the page is right before and without JavaScript.
 */
final class SeriesPageUiTest extends WebTestCase
{
    private const string SPRINT = '/en/series/moonlight-sprint-league';
    private const string HARBOR = '/en/series/harbor-jigsaw-nights';
    private const string CLOCK = '/en/series/lakeside-clock-marathon';
    private const string SUMMIT = '/en/series/summit-puzzle-league';
    private const string SEASON_ONE = '/en/series/moonlight-sprint-league/season-one';

    public function testTheNextCardIsTheNextSessionOfAMultiSessionEdition(): void
    {
        $crawler = $this->page(self::createClient(), self::SPRINT);

        $next = $crawler->filter('[data-series-next]');
        self::assertCount(1, $next);
        self::assertSame(EventsPageFixture::EDITION_SPRINT_SEASON, $next->attr('data-series-edition'));
        self::assertSame('Next', trim($next->filter('h2')->text()));

        $title = $next->filter('a.ev-next-title');
        self::assertSame('Season One · Sprint 3', trim($title->text()));
        self::assertSame(self::SEASON_ONE . '#round-' . EventsPageFixture::ROUND_SPRINT_3, $title->attr('href'));

        // The start time in the event's zone, the zone named; online: the slot for the visitor's own time
        self::assertSame('22:00 Eastern Time', trim($next->filter('.ev-time')->text()));
        self::assertCount(1, $next->filter('[data-local-time][hidden]'));
        self::assertSame('Online', trim($next->filter('.ev-place-online')->text()));
        // In 25 days: the countdown
        self::assertMatchesRegularExpression('/^In \d+ days$/', trim($next->filter('.ev-next-when')->text()));

        // A guest says "I'm going" through the join link
        self::assertCount(1, $next->filter(sprintf('a[href="/en/join-event/%s"]', EventsPageFixture::EDITION_SPRINT_SEASON)));
    }

    public function testTheNextSessionIsNotRepeatedBelowAndTheOthersAreRowsByMonth(): void
    {
        $crawler = $this->page(self::createClient(), self::SPRINT);

        $rows = $crawler->filter('[data-series-upcoming] .ev-row');
        self::assertCount(1, $rows, 'Sprint 4 only - Sprint 3 is the Next card');
        self::assertSame('Season One · Sprint 4', trim($rows->filter('.ev-row-name')->text()));
        self::assertSame(self::SEASON_ONE . '#round-' . EventsPageFixture::ROUND_SPRINT_4, $rows->filter('.ev-row-name')->attr('href'));
        self::assertSame('22:00 Eastern Time', trim($rows->filter('.ev-time')->text()));
        // No star on series rows - the header's follows the series
        self::assertCount(0, $rows->filter('.ev-star'));
        self::assertCount(0, $rows->filter('.ev-tag-recurring'));

        // Under its own month header, which counts its dates
        $month = $crawler->filter('[data-series-upcoming] h3.ev-month-header');
        self::assertCount(1, $month);
        self::assertSame('1 date', trim($month->filter('.ev-month-n')->text()));
        self::assertSame('Upcoming 1', trim(preg_replace('/\s+/', ' ', $crawler->filter('#series-upcoming-title')->text()) ?? ''));
    }

    public function testPastSessionsAreLinesWithResultsOnlyWhereTheSessionHasSome(): void
    {
        $crawler = $this->page(self::createClient(), self::SPRINT);

        $lines = $crawler->filter('[data-series-past] .ev-line');
        self::assertCount(2, $lines);

        // Newest first: Sprint 2, then Sprint 1 (they may fall into two years)
        $sprint2 = $lines->eq(0);
        $sprint1 = $lines->eq(1);
        self::assertSame('Season One · Sprint 2', trim($sprint2->filter('.ev-line-title')->text()));
        self::assertSame('Season One · Sprint 1', trim($sprint1->filter('.ev-line-title')->text()));
        self::assertSame(self::SEASON_ONE . '#round-' . EventsPageFixture::ROUND_SPRINT_2, $sprint2->filter('a.ev-line-title')->attr('href'));
        self::assertSame(self::SEASON_ONE . '#round-' . EventsPageFixture::ROUND_SPRINT_1, $sprint1->filter('a.ev-line-title')->attr('href'));

        self::assertCount(0, $sprint2->filter('.ev-tag-results'), 'no results on Sprint 2');
        self::assertSame('Results', trim($sprint1->filter('.ev-tag-results')->text()));
    }

    public function testHarborListsEverySessionAsItsOwnRowAndThePastByYear(): void
    {
        $crawler = $this->page(self::createClient(), self::HARBOR);

        self::assertSame(EventsPageFixture::EDITION_HARBOR_1, $crawler->filter('[data-series-next]')->attr('data-series-edition'));
        self::assertSame('Session 1', trim($crawler->filter('[data-series-next] .ev-next-title')->text()));

        // Sessions 2 and 3 as two rows of one month - no roll-up on the series page
        $rows = $crawler->filter('[data-series-upcoming] .ev-rows')->first()->filter('.ev-row');
        self::assertSame(['Session 2', 'Session 3'], $rows->each(static fn (Crawler $row): string => trim($row->filter('.ev-row-name')->text())));
        self::assertCount(0, $crawler->filter('[data-series-upcoming] .ev-sessions'));

        // Last year's two editions under that year
        $lastYear = (int) new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y') - 1;
        $year = $crawler->filter(sprintf('[data-series-past] section[data-year="%d"]', $lastYear));
        self::assertCount(1, $year);
        self::assertSame((string) $lastYear, trim($year->filter('h3 span')->first()->text()));
        self::assertSame(['Summer Session', 'Spring Session'], $year->filter('.ev-line-title')->each(static fn (Crawler $title): string => trim($title->text())));
        // One year: no chips
        self::assertCount(0, $crawler->filter('[data-series-past] button[data-year]'));

        // The undated edition, last, under "Date not set"
        self::assertSame('Date not set', trim($crawler->filter('[data-series-date-not-set] span')->first()->text()));
        $undated = $crawler->filter(sprintf('[data-series-edition="%s"]', EventsPageFixture::EDITION_HARBOR_UNDATED));
        self::assertCount(1, $undated);
        self::assertSame('Date not set', trim($undated->filter('[data-edition-date-not-set]')->text()));
        self::assertSame(EventsPageFixture::EDITION_HARBOR_UNDATED, $crawler->filter('[data-series-edition]')->last()->attr('data-series-edition'));
    }

    public function testTheFactsStripCountsEditionsAndComingSessions(): void
    {
        $crawler = $this->page(self::createClient(), self::HARBOR);

        $facts = $crawler->filter('[data-series-facts]');
        // 3 coming, 2 past, 1 undated
        self::assertSame('6 editions', trim($facts->filter('[data-series-fact="editions"]')->text()));
        self::assertSame('3 coming', trim($facts->filter('[data-series-fact="coming"]')->text()));
        self::assertStringStartsWith('since ', trim($facts->filter('[data-series-fact="since"]')->text()));
        self::assertStringStartsWith('next ', trim($facts->filter('[data-series-fact="next"]')->text()));
        // No "how often"
        self::assertStringNotContainsString('about', $facts->text());

        $side = $crawler->filter('.ev-series-side');
        self::assertSame('About', trim($side->filter('h2')->text()));
        self::assertSame('Editions', trim($side->filter('dt')->first()->text()));
        self::assertSame('6', trim($side->filter('dd')->first()->text()));
    }

    public function testALongSpanRunningNowIsOngoingWithRunsUntil(): void
    {
        $crawler = $this->page(self::createClient(), self::CLOCK);

        self::assertCount(0, $crawler->filter('[data-series-next]'), 'a long span is never the Next card');
        self::assertSame('No upcoming dates yet.', trim($crawler->filter('[data-series-no-upcoming]')->text()));
        self::assertSame('Ongoing', trim($crawler->filter('[data-series-ongoing] span')->first()->text()));

        $row = $crawler->filter(sprintf('[data-series-upcoming] [data-series-edition="%s"]', EventsPageFixture::EDITION_CLOCK_LONG));
        self::assertCount(1, $row);
        self::assertStringStartsWith('Runs until', trim($row->filter('.ev-tag-runs_until')->text()));
        self::assertCount(0, $row->filter('.live-dot'), 'never Live');

        // In person: no visitor's time, no controller for it
        self::assertCount(0, $crawler->filter('[data-controller~="event-local-time"]'));
        self::assertCount(0, $crawler->filter('[data-local-time]'));
    }

    public function testASeriesWithoutEditionsSaysSo(): void
    {
        $crawler = $this->page(self::createClient(), self::SUMMIT);

        self::assertSame('No editions yet.', trim($crawler->filter('[data-series-empty] p')->text()));
        self::assertCount(0, $crawler->filter('[data-series-next]'));
        self::assertCount(0, $crawler->filter('[data-series-past]'));
        self::assertCount(0, $crawler->filter('[data-series-facts]'));
        // Visitors get no "Add edition"
        self::assertCount(0, $crawler->filter('[data-series-empty] a'));
    }

    public function testTheOrganiserOfASeriesWithoutEditionsCanAddOneRightThere(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->page($browser, self::SUMMIT);

        $add = $crawler->filter('[data-series-empty] a');
        self::assertCount(1, $add);
        self::assertStringStartsWith('/en/add-edition/' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, (string) $add->attr('href'));
    }

    public function testAFollowerSeesFollowingSeriesPressed(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $this->page($browser, self::HARBOR);

        $star = $crawler->filter('.ev-detail-actions button.ev-star-labelled');
        self::assertCount(1, $star);
        self::assertSame('true', $star->attr('aria-pressed'));
        self::assertSame('Following series', trim($star->filter('[data-follow-text]')->text()));
        self::assertCount(1, $crawler->filter('.ev-series-following'), 'the follower note in the side column');
    }

    public function testAGuestGetsTheSignInLinkToFollow(): void
    {
        $crawler = $this->page(self::createClient(), self::HARBOR);

        $star = $crawler->filter('.ev-detail-actions a.ev-star-labelled');
        self::assertCount(1, $star);
        self::assertStringStartsWith('/login', (string) $star->attr('href'));
        self::assertSame('Follow series', trim($star->filter('[data-follow-text]')->text()));
        self::assertCount(0, $crawler->filter('.ev-series-following'));
    }

    public function testTheOrganiserSeesTheHeaderAndRowMenus(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $this->page($browser, self::HARBOR);

        self::assertCount(1, $crawler->filter('.ev-detail-actions .ev-manage'));
        self::assertGreaterThan(0, $crawler->filter('[data-series-upcoming] .ev-row .ev-manage')->count());
        self::assertCount(1, $crawler->filter('[data-series-next] .ev-manage'));
        self::assertCount(1, $crawler->filter('[data-controller="event-manage-menu"]'));
    }

    public function testAnotherPlayerSeesNoMenus(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->page($browser, self::HARBOR);

        self::assertCount(0, $crawler->filter('.ev-manage'));
        self::assertCount(0, $crawler->filter('[data-controller="event-manage-menu"]'));
    }

    public function testAnUnapprovedSeriesHasNoStar(): void
    {
        $crawler = $this->page(self::createClient(), '/en/series/pending-puzzle-league');

        self::assertCount(0, $crawler->filter('.ev-star'));
    }

    public function testAnOnlineSeriesCarriesTheLocalTimeController(): void
    {
        $crawler = $this->page(self::createClient(), self::SPRINT);

        self::assertCount(1, $crawler->filter('.ev-detail[data-controller="event-local-time"]'));
    }

    public function testARunningSessionIsTheNextCardLive(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement(
            "UPDATE competition_round SET starts_at = NOW() - INTERVAL '10 minutes' WHERE id = :id",
            ['id' => EventsPageFixture::ROUND_SPRINT_3],
        );

        $crawler = $this->page($browser, self::SPRINT);

        $next = $crawler->filter('[data-series-next]');
        self::assertSame('Season One · Sprint 3', trim($next->filter('.ev-next-title')->text()));
        self::assertSame('Live', trim($next->filter('.ev-when-live')->text()));
        self::assertSame('live now', trim($crawler->filter('[data-series-fact="next"]')->text()));
        self::assertCount(1, $crawler->filter('.ev-detail-facts .live-dot'), 'the header says Live too');
    }

    public function testSeveralPastYearsGetYearChipsAndAShowAllButton(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $twoYearsAgo = (int) new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y') - 2;

        // Six editions two years ago: a second year, with more lines than the preview shows
        for ($i = 1; $i <= 6; $i++) {
            $date = sprintf('%d-%02d-10', $twoYearsAgo, $i);
            $connection->executeStatement(
                "INSERT INTO competition (id, name, slug, series_id, is_online, created_at, date_from, date_to)
                 VALUES (:id, :name, :slug, :series, true, NOW(), :date, :date)",
                ['id' => Uuid::uuid7()->toString(), 'name' => 'Old Session ' . $i, 'slug' => 'old-session-' . $i, 'series' => EventsPageFixture::SERIES_HARBOR_NIGHTS, 'date' => $date],
            );
        }

        $crawler = $this->page($browser, self::HARBOR);
        $past = $crawler->filter('[data-series-past]');
        self::assertSame('series-archive', $past->attr('data-controller'));

        $chips = $past->filter('[data-series-archive-target="chips"]');
        self::assertCount(1, $chips);
        self::assertNotNull($chips->attr('hidden'), 'without JavaScript every year shows under its heading - the chips come with it');
        self::assertSame('Years', $chips->attr('aria-label'));

        $buttons = $chips->filter('button');
        self::assertCount(2, $buttons);
        $lastYear = $twoYearsAgo + 1;
        self::assertSame((string) $lastYear, $buttons->eq(0)->attr('data-year'));
        self::assertSame('true', $buttons->eq(0)->attr('aria-pressed'), 'the newest year is chosen');
        self::assertSame('series-past-' . $lastYear, $buttons->eq(0)->attr('aria-controls'));
        self::assertSame('false', $buttons->eq(1)->attr('aria-pressed'));
        self::assertSame('series-past-' . $twoYearsAgo, $buttons->eq(1)->attr('aria-controls'));
        self::assertSame($twoYearsAgo . ' 6', trim(preg_replace('/\s+/', ' ', $buttons->eq(1)->text()) ?? ''));

        // Every line is in the HTML; the browser shows the first five and "Show all"
        $oldYear = $past->filter('#series-past-' . $twoYearsAgo);
        self::assertCount(6, $oldYear->filter('.ev-line'));
        self::assertSame('Show all ' . $twoYearsAgo . ' (6)', trim($oldYear->filter('button[data-action="series-archive#showAll"]')->text()));
        self::assertCount(0, $past->filter('#series-past-' . $lastYear . ' button[data-action="series-archive#showAll"]'), 'two lines need no "Show all"');
        self::assertSame('Past 8', trim(preg_replace('/\s+/', ' ', $crawler->filter('#series-past-title')->text()) ?? ''));
    }

    private function page(KernelBrowser $browser, string $url): Crawler
    {
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
