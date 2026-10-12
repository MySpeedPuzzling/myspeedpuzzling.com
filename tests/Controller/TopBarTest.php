<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use DOMElement;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Navigation\BarAction;
use SpeedPuzzling\Web\Query\GetAdminQueueCounts;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\Navigation\NavigationViewer;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\StopwatchStatus;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The top bar (docs/features/navigation README §4.1 / D2, D5; implementation plan NAV-03, NAV-10, NAV-14): one
 * <header class="site-header"> on every web page, a direct child of <body> - phones: (back) · logo · search field ·
 * (⏱ pill) · 🔍 · ? · Inbox · ☰. Built from the request and the profile row only (ChromeQueryBudgetTest pins that it
 * costs no statement): the logo is Home (the Hub signed in, the homepage for guests), Inbox for signed-in players only,
 * ☰ last with a dot only while one of a moderator's or admin's review queues holds items, the installed app's back
 * button (shown by CSS under html.is-standalone) on every page but the places' own start pages. The four tools carry the
 * BarAction ids. The page's GlobalSearch is the field - the first instance in the DOM. Guest HTML stays shared.
 */
final class TopBarTest extends WebTestCase
{
    // A non-member without roles or a stopwatch (the fixtures' running stopwatch belongs to PLAYER_REGULAR)
    private const string PLAYER = PlayerFixture::PLAYER_PRIVATE;

    public function testAPlayersTopBarInOrder(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $header = $crawler->filter('body > header.site-header');
        self::assertCount(1, $header);
        self::assertCount(1, $crawler->filter('body > header'), 'One banner: the old rows are no <header> any more');
        self::assertNotNull($header->attr('data-viewer-signed-in'));
        self::assertSame('topbar', $header->attr('data-controller'));

        // The FAQ is no place's start page: the installed app's back button comes first (CSS shows it there only)
        self::assertSame(['back', 'logo', 'search', 'search_icon', 'help', 'inbox', 'menu'], $this->tools($crawler));

        self::assertSame($router->generate('hub'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame($router->generate('help', ['url' => '/en/faq']), $header->filter('[data-bar-action="help"]')->attr('href'));
        self::assertSame($router->generate('puzzles'), $header->filter('[data-bar-action="search"]')->attr('href'));
        self::assertSame($router->generate('menu'), $header->filter('[data-bar-action="menu"]')->attr('href'));

        // ☰: last, named "Menu", a dialog opener; no dot for a player without review queues
        $menu = $header->filter('.site-header-inner > *')->last();
        self::assertSame('menu', $menu->attr('data-bar-action'));
        self::assertSame('Menu', $menu->attr('aria-label'));
        self::assertSame('dialog', $menu->attr('aria-haspopup'));
        self::assertSame('false', $menu->attr('aria-expanded'));
        self::assertCount(0, $header->filter('.nav-menu-dot'));
        // The Menu panel's focus fallback reads the first [data-bar-action="menu"] of the page: the ☰ (review F7)
        self::assertSame('topbar/none/menu', $crawler->filter('[data-bar-action="menu"]')->first()->attr('data-nav'));

        // The search field is the page's GlobalSearch - the first instance in the DOM
        $search = $header->filter('[data-live-name-value="GlobalSearch"]');
        self::assertCount(1, $search);
        self::assertSame('bar', $search->attr('data-global-search-mode-value'));
        self::assertSame('bar', $crawler->filter('[data-live-name-value="GlobalSearch"]')->first()->attr('data-global-search-mode-value'));
        $input = $search->filter('input.site-search-input');
        self::assertSame('search', $input->attr('type'));
        self::assertSame('Search', $input->attr('placeholder'));
        self::assertSame('Search puzzles, puzzlers and pages', $input->attr('aria-label'));
        // No scan teaser in the closed bar (decision 21): a non-member's scan button is the locked one, shown only open
        self::assertCount(1, $search->filter('button.site-search-scan.site-search-scan--locked'));

        // Chrome markup rules (implementation plan §3.2): no heading, no form, own class names (never one starting topbar)
        self::assertCount(0, $header->filter('h1, h2, form, .alert, .card-body, .nav-tabs, .nav-pills, .nav-link, [class^="topbar"], [class*=" topbar"]'));

        foreach ($header->filter('a, button') as $element) {
            assert($element instanceof DOMElement);
            self::assertNotSame('', $element->getAttribute('data-nav'), $element->getAttribute('class'));
        }
    }

    public function testTheBarActionsAreTheEnumsCases(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);

        $crawler = $browser->request('GET', '/en/faq');
        $header = $crawler->filter('body > header.site-header');

        $actions = $header->filter('[data-bar-action]')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-bar-action'));
        $cases = array_map(static fn (BarAction $action): string => $action->value, BarAction::cases());

        self::assertSame($cases, $actions);

        foreach ($header->filter('[data-bar-action]') as $tool) {
            assert($tool instanceof DOMElement);
            self::assertNotSame('', $tool->getAttribute('aria-label'), $tool->getAttribute('data-bar-action') . ' has no accessible name');
            self::assertNotSame('', $tool->getAttribute('href'), $tool->getAttribute('data-bar-action') . ' has no plain URL');
        }

        // The Inbox tool also tells its count
        self::assertMatchesRegularExpression('~^\d+$~', (string) $header->filter('[data-bar-action="inbox"]')->attr('data-bar-count'));
        // ... and shows it as one badge of the total on the icon (the link's own classes never leak into it)
        $badge = $header->filter('[data-bar-action="inbox"] .inbox-badge');
        self::assertCount(1, $badge);
        self::assertSame('inbox-badge', $badge->attr('class'));
        self::assertSame('total', $badge->attr('data-inbox-part'));
    }

    public function testAGuestsTopBarStaysShared(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $header = $crawler->filter('body > header.site-header');
        self::assertSame(['back', 'logo', 'search', 'search_icon', 'help', 'menu'], $this->tools($crawler));
        self::assertCount(0, $header->filter('[data-bar-action="inbox"], a[data-inbox-link]'), 'Inbox is for signed-in players');
        self::assertNull($header->attr('data-viewer-signed-in'));
        // D5: guests' Home is the homepage
        self::assertSame($router->generate('homepage'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame(['search', 'help', 'menu'], $header->filter('[data-bar-action]')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-bar-action')));

        $response = $browser->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'), (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
    }

    /**
     * README §4.1: the installed app's back button on every page but the places' own start pages (hub, homepage, the
     * Puzzles sections, Add, the Community landing, your own profile) - server-rendered with .standalone-only, shown by
     * CSS under html.is-standalone. Its plain link is the start page of the page's place.
     */
    public function testTheInstalledAppsBackButtonOnlyOffTheStartPages(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $router = self::getContainer()->get(RouterInterface::class);

        $roots = [
            $router->generate('hub'),
            $router->generate('puzzles'),
            $router->generate('puzzle_library', ['playerId' => self::PLAYER]),
            $router->generate('puzzle_add'),
            $router->generate('community'),
            $router->generate('player_profile', ['playerId' => self::PLAYER]),
        ];

        foreach ($roots as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(0, $crawler->filter('.site-header .site-header-back'), $url . ' is a start page: no back button');
            self::assertStringNotContainsString('site-header--back', (string) $crawler->filter('.site-header')->attr('class'), $url);
        }

        // A puzzle page belongs to Puzzles: back leads to the player's library (the Puzzles tab's own link)
        $crawler = $browser->request('GET', $router->generate('puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]));
        self::assertResponseIsSuccessful();
        $back = $crawler->filter('.site-header .site-header-back');
        self::assertCount(1, $back);
        self::assertStringContainsString('standalone-only', (string) $back->attr('class'));
        self::assertSame('Back', $back->attr('aria-label'));
        self::assertSame('topbar#back', $back->attr('data-action'));
        self::assertSame($router->generate('puzzle_library', ['playerId' => self::PLAYER]), $back->attr('href'));
        self::assertSame(['back', 'logo'], array_slice($this->tools($crawler), 0, 2), 'The back button comes before the logo');

        // Somebody else's profile belongs to Community: back to its landing
        $crawler = $browser->request('GET', $router->generate('player_profile', ['playerId' => PlayerFixture::PLAYER_REGULAR]));
        self::assertSame($router->generate('community'), $crawler->filter('.site-header .site-header-back')->attr('href'));

        // Guests: the homepage and the Add explainer are start pages too
        $guest = $this->guest($browser);

        foreach ([$router->generate('homepage'), $router->generate('add_intro')] as $url) {
            $crawler = $guest->request('GET', $url);
            self::assertResponseIsSuccessful($url);
            self::assertCount(0, $crawler->filter('.site-header .site-header-back'), $url);
        }
    }

    /**
     * README §4.12 "Menu data": the ☰ dot only for moderators and admins while one of their queues holds items - read
     * from the memoised admin_queue_counts() statement the Menu's Your roles rows already ran
     */
    public function testTheMenuDotForAModeratorWithABacklog(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MODERATOR);

        $counts = self::getContainer()->get(GetAdminQueueCounts::class)->forViewer(isAdmin: false);
        self::assertGreaterThan(0, $counts->changeRequests + $counts->mergeRequests + $counts->puzzleApprovals + $counts->timeVerification, 'Premise: the fixtures leave a moderator queue with items');

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $dot = $crawler->filter('.site-header [data-bar-action="menu"] .nav-menu-dot');
        self::assertCount(1, $dot);
        self::assertSame('true', $dot->attr('aria-hidden'));
    }

    public function testNoMenuDotForAMemberOrAnOrganiser(): void
    {
        $browser = self::createClient();

        foreach ([NavigationViewer::MEMBER, NavigationViewer::ORGANISER] as $role) {
            NavigationViewer::signIn($browser, $role);

            $crawler = $browser->request('GET', '/en/faq');
            self::assertResponseIsSuccessful($role);
            self::assertCount(0, $crawler->filter('.site-header .nav-menu-dot'), $role);
        }
    }

    /**
     * ADD-03 placed by NAV-03: a running stopwatch's pill sits in the search field's slot (CSS then shows the 🔍 icon in
     * the field's place on phones); a paused one shows nothing
     */
    public function testTheRunningStopwatchPillTakesTheFieldsSlot(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $database = self::getContainer()->get(Connection::class);
        $now = self::getContainer()->get(ClockInterface::class)->now();

        $id = $this->insertStopwatch($database, $now, StopwatchStatus::Running);
        $crawler = $browser->request('GET', '/en/faq');

        self::assertSame(['back', 'logo', 'search', 'pill', 'search_icon', 'help', 'inbox', 'menu'], $this->tools($crawler));
        self::assertStringContainsString('site-header--stopwatch', (string) $crawler->filter('.site-header')->attr('class'));
        self::assertSame('/en/stopwatch/' . $id, $crawler->filter('.site-header > .site-header-inner > a[data-stopwatch-pill]')->attr('href'));

        $database->executeStatement('UPDATE stopwatch SET status = :status WHERE id = :id', ['status' => StopwatchStatus::Paused->value, 'id' => $id]);
        $crawler = $browser->request('GET', '/en/faq');

        self::assertSame(['back', 'logo', 'search', 'search_icon', 'help', 'inbox', 'menu'], $this->tools($crawler));
        self::assertStringNotContainsString('site-header--stopwatch', (string) $crawler->filter('.site-header')->attr('class'));
    }

    public function testTheNativeAppGetsNoTopBar(): void
    {
        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);
            TestingLogin::asPlayer($browser, self::PLAYER);

            $crawler = $browser->request('GET', '/en/faq');
            self::assertResponseIsSuccessful($name);
            self::assertCount(0, $crawler->filter('.site-header, [data-bar-action]'), $name);
            NavChromeAssertions::assertNoWebChrome($crawler, $name);

            self::ensureKernelShutdown();
        }
    }

    /**
     * The top bar's tools in DOM order, by role
     *
     * @return list<string>
     */
    private function tools(Crawler $crawler): array
    {
        return $crawler->filter('body > header.site-header > .site-header-inner > *')->each(static function (Crawler $tool): string {
            $class = ' ' . $tool->attr('class') . ' ';

            return match (true) {
                str_contains($class, ' site-header-back ') => 'back',
                str_contains($class, ' site-header-logo ') => 'logo',
                str_contains($class, ' site-search ') => 'search',
                str_contains($class, ' stopwatch-pill ') => 'pill',
                str_contains($class, ' site-search-icon ') => 'search_icon',
                str_contains($class, ' site-header-help ') => 'help',
                str_contains($class, ' inbox-link ') => 'inbox',
                str_contains($class, ' site-header-menu ') => 'menu',
                default => 'unknown: ' . trim($class),
            };
        });
    }

    private function guest(KernelBrowser $browser): KernelBrowser
    {
        $browser->getCookieJar()->clear();

        return $browser;
    }

    private function insertStopwatch(Connection $database, DateTimeImmutable $now, StopwatchStatus $status): string
    {
        $id = Uuid::uuid7()->toString();

        $database->insert('stopwatch', [
            'id' => $id,
            'player_id' => self::PLAYER,
            'puzzle_id' => null,
            'status' => $status->value,
            'laps' => json_encode([['start' => $now->modify('-10 minutes')->format('Y-m-d H:i:s'), 'end' => null]], JSON_THROW_ON_ERROR),
        ]);

        return $id;
    }
}
