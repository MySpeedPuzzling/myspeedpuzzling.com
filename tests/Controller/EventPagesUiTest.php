<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventDetailFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The edition and one-time event pages (docs/features/events-page/detail-pages.md "Edition page and one-time event
 * page"): the header, the rounds timeline - folding, the next round, times and zones, puzzles in their round, secret
 * puzzles, links per round - and Taking part.
 */
final class EventPagesUiTest extends WebTestCase
{
    private const string SEASON_ONE_URL = '/en/series/moonlight-sprint-league/season-one';
    private const string HILLTOP_URL = '/en/events/hilltop-puzzle-weekend';

    public function testSeasonOneFoldsTheEarlierRoundAndHighlightsTheNextOne(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            [EventsPageFixture::ROUND_SPRINT_1, EventsPageFixture::ROUND_SPRINT_2, EventsPageFixture::ROUND_SPRINT_3, EventsPageFixture::ROUND_SPRINT_4],
            $crawler->filter('li.ev-round')->each(static fn (Crawler $round): string => substr((string) $round->attr('id'), strlen('round-'))),
        );

        // Sprint 1 (past) behind "Show 1 earlier round"; Sprint 2, the latest past one, stays in sight
        $earlier = $crawler->filter('details.ev-rounds-earlier');
        self::assertCount(1, $earlier);
        self::assertSame('Show 1 earlier round', trim($earlier->filter('.ev-rounds-earlier-show')->text()));
        self::assertCount(1, $earlier->filter('#round-' . EventsPageFixture::ROUND_SPRINT_1));
        self::assertCount(0, $earlier->filter('#round-' . EventsPageFixture::ROUND_SPRINT_2));
        // The controller that opens it for a #round-<id> link is there only because something is folded
        self::assertSame('event-rounds', $crawler->filter('[data-event-rounds]')->attr('data-controller'));

        // Sprint 3 is the next round: highlighted, announced, counted down, its secret puzzle not announced
        $next = $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_3);
        self::assertStringContainsString('ev-round-next', (string) $next->attr('class'));
        self::assertStringContainsString('Next round:', $next->filter('.ev-round-title .visually-hidden')->text());
        self::assertCount(1, $next->filter('.ev-when'));
        self::assertStringContainsString('Puzzles not announced yet', $next->text());
        self::assertCount(0, $next->filter('[data-round-puzzle]'));
        self::assertSame('past', $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_2)->attr('data-round-status'));
        self::assertSame('later', $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_4)->attr('data-round-status'));
    }

    public function testTheSecretPuzzleOfTheNextRoundIsNowhereInThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertStringNotContainsString(PuzzleFixture::PUZZLE_500_03, (string) $browser->getResponse()->getContent());
    }

    public function testRoundsLinkTheirResultsAndAddMyTime(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        $sprint1 = $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_1);
        self::assertSame(self::SEASON_ONE_URL . '/results/sprint-1', $sprint1->filter('[data-round-results-link]')->attr('href'));
        // One puzzle with its picture shown: the add-time form gets it pre-selected
        self::assertSame(
            sprintf('/en/puzzle-add/%s?competition=%s', PuzzleFixture::PUZZLE_500_01, EventsPageFixture::EDITION_SPRINT_SEASON),
            $sprint1->filter('[data-round-add-time]')->attr('href'),
        );
        // Its puzzle is a compact item linking the puzzle page
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, $sprint1->filter(sprintf('[data-round-puzzle="%s"] a', PuzzleFixture::PUZZLE_500_01))->attr('href'));

        // Nobody logged a time in Sprint 2 - no results page to link
        $sprint2 = $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_2);
        self::assertCount(0, $sprint2->filter('[data-round-results-link]'));
        self::assertCount(1, $sprint2->filter('[data-round-add-time]'));

        // Rounds that have not started offer neither
        self::assertCount(0, $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_3 . ' [data-round-add-time]'));
        self::assertCount(0, $crawler->filter('#round-' . EventsPageFixture::ROUND_SPRINT_4 . ' [data-round-results-link]'));
    }

    public function testAGuestGetsNoAddMyTime(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('[data-round-add-time]'));
        self::assertCount(1, $crawler->filter('[data-round-results-link]'));
    }

    public function testOnlineRoundsNameTheEventsZoneAndLeaveRoomForTheVisitorsTime(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['Eastern Time', 'Eastern Time', 'Eastern Time', 'Eastern Time'],
            $crawler->filter('li.ev-round [data-round-zone]')->each(static fn (Crawler $zone): string => $zone->text()),
        );
        self::assertSame(['22:00', '22:00', '22:00', '22:00'], $crawler->filter('li.ev-round time[data-event-time]')->each(static fn (Crawler $time): string => $time->text()));
        self::assertCount(4, $crawler->filter('li.ev-round [data-local-time][hidden]'));
        self::assertSame('event-local-time', $crawler->filter('.ev-detail')->attr('data-controller'));
        self::assertStringContainsString('Times are in Eastern Time', $crawler->filter('.ev-detail-side')->text());
    }

    public function testEditionHeaderCarriesTheSeriesAndItsFacts(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertSame(
            ['/en/events', '/en/series/moonlight-sprint-league'],
            $crawler->filter('.ev-crumbs a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame('Season One', trim($crawler->filter('h1')->text()));
        $facts = $crawler->filter('.ev-detail-facts')->text();
        self::assertStringContainsString('Online', $facts);
        self::assertStringContainsString('Recurring', $facts);
        self::assertStringContainsString('4 rounds', $facts);
        // Phones: the next round and how many rounds have results, under the header
        $strip = $crawler->filter('[data-event-facts]')->text();
        self::assertStringContainsString('Next ', $strip);
        self::assertStringContainsString('22:00 Eastern Time', $strip);
        self::assertStringContainsString('1 round with results', $strip);
        // The side column: part of the series, the next round
        self::assertSame('/en/series/moonlight-sprint-league', $crawler->filter('.ev-detail-side a')->first()->attr('href'));
        self::assertSame('#round-' . EventsPageFixture::ROUND_SPRINT_3, $crawler->filter('.ev-detail-side a[href^="#round-"]')->attr('href'));
    }

    public function testMultiDayChampionshipInPersonHasALeafPerRoundAndOnlyItsOwnZone(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        $rounds = $crawler->filter('li.ev-round');
        self::assertCount(3, $rounds);
        self::assertCount(3, $rounds->filter('.ev-leaf'));
        self::assertCount(0, $crawler->filter('.ev-round-same-day'));
        self::assertCount(3, $rounds->filter('.ev-leaf-in_person'));
        // Nothing folded before the event; the Friday round is the next one
        self::assertCount(0, $crawler->filter('details.ev-rounds-earlier'));
        self::assertNull($crawler->filter('[data-event-rounds]')->attr('data-controller'));
        self::assertSame('next', $crawler->filter('#round-' . EventDetailFixture::ROUND_HILLTOP_FRI)->attr('data-round-status'));
        self::assertSame(
            ['solo', 'duo', 'solo'],
            $rounds->filter('[data-round-category]')->each(static fn (Crawler $pill): string => (string) $pill->attr('data-round-category')),
        );
        self::assertSame(['18:00', '10:00', '10:00'], $rounds->filter('time[data-event-time]')->each(static fn (Crawler $time): string => $time->text()));
        self::assertSame(
            ['Central European Time', 'Central European Time', 'Central European Time'],
            $rounds->filter('[data-round-zone]')->each(static fn (Crawler $zone): string => $zone->text()),
        );
        // In person: never a second time, no controller for it
        self::assertCount(0, $crawler->filter('[data-local-time]'));
        self::assertNull($crawler->filter('.ev-detail')->attr('data-controller'));
        self::assertStringContainsString('where the event takes place', $crawler->filter('.ev-detail-side')->text());
        self::assertStringContainsString('3 rounds', $crawler->filter('.ev-detail-facts')->text());
    }

    public function testAPictureHiddenUntilTheRoundStartsShowsATileAndNoLink(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        $puzzle = $crawler->filter(sprintf('#round-%s [data-round-puzzle="%s"]', EventDetailFixture::ROUND_HILLTOP_SAT, PuzzleFixture::PUZZLE_500_04));
        self::assertCount(1, $puzzle);
        self::assertCount(1, $puzzle->filter('.ev-round-puzzle-tile'));
        self::assertCount(0, $puzzle->filter('img'));
        self::assertCount(0, $puzzle->filter('a'));
        self::assertStringContainsString('Picture revealed when the round starts', $puzzle->text());
        self::assertStringNotContainsString('/en/puzzle/' . PuzzleFixture::PUZZLE_500_04, (string) $browser->getResponse()->getContent());
    }

    public function testRoundsOnTheSameDayShowTheDateOnce(): void
    {
        $browser = self::createClient();
        // The Sunday final moves to Saturday afternoon - two rounds on one day
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET starts_at = (SELECT starts_at FROM competition_round WHERE id = :saturday) + INTERVAL '4 hours' WHERE id = :sunday",
            ['saturday' => EventDetailFixture::ROUND_HILLTOP_SAT, 'sunday' => EventDetailFixture::ROUND_HILLTOP_SUN],
        );

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        $sameDay = $crawler->filter('.ev-round-same-day');
        self::assertCount(1, $sameDay);
        self::assertSame('round-' . EventDetailFixture::ROUND_HILLTOP_SUN, $sameDay->attr('id'));
        // A screen reader still hears its date
        self::assertNotSame('', trim($sameDay->filter('.ev-round-title .visually-hidden')->text()));
    }

    public function testEditionStarFollowsTheSeries(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR follows Harbor Jigsaw Nights
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/series/harbor-jigsaw-nights/session-1');

        $this->assertResponseIsSuccessful();
        $star = $crawler->filter('.ev-detail-actions button.ev-star-labelled');
        self::assertCount(1, $star);
        self::assertSame('true', $star->attr('aria-pressed'));
        self::assertSame('Following series', trim($star->filter('[data-follow-text]')->text()));
        self::assertNull($star->attr('aria-label'));
    }

    public function testOneTimeEventStarFollowsItself(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR follows Meadow Puzzle Championship
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/events/meadow-puzzle-championship');

        $this->assertResponseIsSuccessful();
        $star = $crawler->filter('.ev-detail-actions button.ev-star-labelled');
        self::assertSame('true', $star->attr('aria-pressed'));
        self::assertSame('Following', trim($star->filter('[data-follow-text]')->text()));
        // Its external registration link, in the header (the event has no rounds to carry it)
        self::assertCount(1, $crawler->filter('.ev-detail-actions a[href^="https://example.com/meadow/register"]'));
    }

    public function testAGuestGetsTheSignInFollowLink(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/meadow-puzzle-championship');

        $this->assertResponseIsSuccessful();
        $star = $crawler->filter('.ev-detail-actions a.ev-star-guest');
        self::assertCount(1, $star);
        self::assertSame('Follow', trim($star->filter('[data-follow-text]')->text()));
        self::assertStringStartsWith('/login', (string) $star->attr('href'));
    }

    public function testManagedRegistrationLinksDownToTheRegistrationCard(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/riverside-puzzle-open');

        $this->assertResponseIsSuccessful();
        self::assertSame('Registration', trim($crawler->filter('.ev-detail-actions a[href="#registration"]')->text()));
        $card = $crawler->filter('#registration');
        self::assertCount(1, $card);
        self::assertNotNull($card->attr('data-registration-card'));
        // The card sits in Taking part
        self::assertCount(1, $crawler->filter('#taking-part #registration'));
        // No "I'm going" next to a managed registration
        self::assertCount(0, $crawler->filter(sprintf('.ev-detail-actions a[href="/en/join-event/%s"]', EventsPageFixture::COMPETITION_RIVERSIDE_OPEN)));
    }

    public function testImGoingInTheHeaderAndYoureGoingLinksToTakingPart(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // Not going yet: "I'm going!" in the header
        $crawler = $browser->request('GET', self::HILLTOP_URL);
        $this->assertResponseIsSuccessful();
        self::assertSame("I'm going!", trim($crawler->filter(sprintf('.ev-detail-actions a[href="/en/join-event/%s"]', EventDetailFixture::COMPETITION_HILLTOP_WEEKEND))->text()));

        $browser->request('GET', '/en/join-event/' . EventDetailFixture::COMPETITION_HILLTOP_WEEKEND);
        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString("You're going!", $crawler->filter('.ev-detail-actions a[href="#taking-part"]')->text());
        self::assertCount(1, $crawler->filter(sprintf('#taking-part form[action="/en/leave-event/%s"]', EventDetailFixture::COMPETITION_HILLTOP_WEEKEND)));
    }

    public function testAnEditionThatIsNotPublicHasNoStarNoResultsAndNoAddMyTime(): void
    {
        $browser = self::createClient();
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET rejected_at = NOW() WHERE id = :id',
            ['id' => EventsPageFixture::EDITION_SPRINT_SEASON],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::SEASON_ONE_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(4, $crawler->filter('li.ev-round'));
        self::assertCount(0, $crawler->filter('.ev-star'));
        self::assertCount(0, $crawler->filter('[data-round-results-link]'));
        self::assertCount(0, $crawler->filter('[data-round-add-time]'));
        self::assertCount(0, $crawler->filter('[data-header-add-time]'));
    }

    public function testAPastEventOffersAddMyTimeOnceInTheHeader(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $lastYear = (int) self::getContainer()->get(ClockInterface::class)->now()->format('Y') - 1;
        $crawler = $browser->request('GET', '/en/events/valley-speed-puzzle-cup-' . $lastYear);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-header-add-time]'));
        self::assertCount(0, $crawler->filter('.ev-taking-part a[href^="/en/add-time"], .ev-taking-part a[href*="competition="]'));
    }

    public function testALongEditionWithoutRoundsRunsUntilItsEndInTheHeader(): void
    {
        // Lakeside Clock Marathon: from 30 days ago to 400 days ahead, no rounds
        $crawler = self::createClient()->request('GET', '/en/series/lakeside-clock-marathon/marathon');

        $this->assertResponseIsSuccessful();
        $facts = $crawler->filter('.ev-detail-facts .ev-detail-fact')->each(static fn (Crawler $fact): string => trim($fact->text()));
        self::assertCount(1, array_filter($facts, static fn (string $fact): bool => str_starts_with($fact, 'Runs until')), implode(' | ', $facts));
    }

    public function testTheSideZoneNoteOnlyWhileEveryRoundSharesTheZone(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', self::HILLTOP_URL);
        self::assertCount(1, $crawler->filter('.ev-detail-side .ev-side-note'));

        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE competition_round SET timezone = 'America/New_York' WHERE id = :id",
            ['id' => EventDetailFixture::ROUND_HILLTOP_SUN],
        );

        // Each round names its own zone - one note naming the first would be wrong
        $crawler = $browser->request('GET', self::HILLTOP_URL);
        self::assertCount(0, $crawler->filter('.ev-detail-side .ev-side-note'));
    }

    public function testAnUnapprovedEventHasNoStar(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/events/unapproved-puzzle-event');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ev-star'));
        self::assertCount(0, $crawler->filter(sprintf('.ev-detail-actions a[href="/en/join-event/%s"]', CompetitionFixture::COMPETITION_UNAPPROVED)));
    }

    public function testOrganiserGetsTheMenuAndTheRoundTools(): void
    {
        $browser = self::createClient();
        // PLAYER_ADMIN created Hilltop Puzzle Weekend
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.ev-detail-actions .ev-manage'));
        self::assertCount(3, $crawler->filter('li.ev-round [data-organiser-round-links]'));
    }

    public function testAPlayerGetsNeitherTheMenuNorTheRoundTools(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.ev-manage'));
        self::assertCount(0, $crawler->filter('[data-organiser-round-links]'));
    }

    public function testReturnUrlPutsTheBackButtonAboveTheBreadcrumb(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::HILLTOP_URL . '?return=/en/puzzle/' . PuzzleFixture::PUZZLE_500_01);

        $this->assertResponseIsSuccessful();
        $back = $crawler->filter(sprintf('a[href="/en/puzzle/%s"]', PuzzleFixture::PUZZLE_500_01));
        self::assertCount(1, $back);
        $content = (string) $browser->getResponse()->getContent();
        self::assertLessThan(
            strpos($content, 'class="ev-crumbs"'),
            strpos($content, sprintf('href="/en/puzzle/%s"', PuzzleFixture::PUZZLE_500_01)),
        );
    }

    public function testWithoutAReturnUrlThereIsNoBackButton(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::HILLTOP_URL);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main .btn-outline-secondary .ci-arrow-left'));
    }
}
