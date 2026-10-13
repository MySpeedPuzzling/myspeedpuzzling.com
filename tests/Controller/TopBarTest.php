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
 * The top bar (docs/features/navigation README §4.1 / D2, §4.3 / D16, D5; implementation plan NAV-03, NAV-04, NAV-10,
 * NAV-14): one <header class="site-header"> on every web page, a direct child of <body>, for phones and desktop (R8) -
 * DOM order ☰ (desktop) · (back) · logo · places (768-1023 px) · search field · (⏱ pill) · 🔍 · + Add (desktop) · ? /
 * Feedback · Inbox · avatar ▾ / Sign in (desktop) · ☰ (phones); CSS shows each surface its own (critical CSS, checked in
 * the browser by checks/NAV-03.py and NAV-04.py). Built from the request and the profile row only (ChromeQueryBudgetTest
 * pins that it costs no statement): the logo is Home (the Hub signed in, the homepage for guests), Inbox and the avatar ▾
 * for signed-in players only - guests get Sign in in its place (decision 23), ☰ with a dot only while one of a
 * moderator's or admin's review queues holds items, the installed app's back button (shown by CSS under
 * html.is-standalone) on every page but the places' own start pages. Each surface's tools carry the BarAction ids. The
 * page's GlobalSearch is the field - the first instance in the DOM. No Puzzles ▾ / Community ▾ dropdowns. Guest HTML
 * stays shared.
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

        // The FAQ is no place's start page: the installed app's back button comes before the logo (CSS shows it there
        // only); the desktop ☰ comes first, the phones' last
        self::assertSame(['menu_desk', 'back', 'logo', 'places', 'search', 'search_icon', 'add', 'help', 'inbox', 'account', 'menu'], $this->tools($crawler));

        self::assertSame($router->generate('hub'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame($router->generate('help', ['url' => '/en/faq']), $header->filter('[data-bar-action="help"]')->attr('href'));
        self::assertSame($router->generate('puzzles'), $header->filter('[data-bar-action="search"]')->attr('href'));
        self::assertSame([$router->generate('menu'), $router->generate('menu')], $header->filter('[data-bar-action="menu"]')->each(static fn (Crawler $menu): string => (string) $menu->attr('href')));

        // ☰ twice (R8): the desktop one first, the phones' last - both named "Menu", dialog openers, the same nav id; no
        // dot for a player without review queues. The focus fallbacks of the Menu panel and the dynamic modal take the
        // one CSS shows (review F7, NAV-04)
        $menus = $header->filter('[data-bar-action="menu"]');
        self::assertCount(2, $menus);
        self::assertSame($menus->first()->getNode(0), $header->filter('.site-header-inner > *')->first()->getNode(0));
        self::assertSame($menus->last()->getNode(0), $header->filter('.site-header-inner > *')->last()->getNode(0));
        self::assertStringContainsString('site-header-menu--desk', (string) $menus->first()->attr('class'));
        self::assertStringContainsString('site-header-menu--phone', (string) $menus->last()->attr('class'));

        foreach ($menus as $menu) {
            assert($menu instanceof DOMElement);
            self::assertSame('Menu', $menu->getAttribute('aria-label'));
            self::assertSame('dialog', $menu->getAttribute('aria-haspopup'));
            self::assertSame('false', $menu->getAttribute('aria-expanded'));
            self::assertSame('topbar/none/menu', $menu->getAttribute('data-nav'));
        }

        self::assertCount(0, $header->filter('.nav-menu-dot'));
        // The desktop ☰ shows its name at 768-1023 px (CSS)
        self::assertSame('Menu', trim($menus->first()->filter('.site-header-menu-label')->text()));

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

        // Chrome markup rules (implementation plan §3.2): no heading, no form, own class names (never one starting topbar);
        // .dropdown-menu only in the avatar ▾, never a .more-menu
        self::assertCount(0, $header->filter('h1, h2, h3, h4, h5, h6, form, .alert, .card-body, .nav-tabs, .nav-pills, .nav-link, .more-menu, [class^="topbar"], [class*=" topbar"]'));
        self::assertCount(1, $header->filter('.dropdown-menu'));
        self::assertCount(1, $header->filter('.site-header-account > .dropdown-menu'));

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

        $cases = array_map(static fn (BarAction $action): string => $action->value, BarAction::cases());

        // Each surface carries every tool once (R8): phones search · help · inbox · menu (☰ last); desktop the same
        // with its ☰ first
        $phone = $header->filter('[data-bar-action]:not(.site-header-menu--desk)')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-bar-action'));
        $desktop = $header->filter('[data-bar-action]:not(.site-header-menu--phone)')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-bar-action'));

        self::assertSame($cases, $phone);
        self::assertSame(['menu', 'search', 'help', 'inbox'], $desktop);
        self::assertEqualsCanonicalizing($cases, $desktop);

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
        self::assertSame(['menu_desk', 'back', 'logo', 'places', 'search', 'search_icon', 'add', 'help', 'sign_in', 'menu'], $this->tools($crawler));
        self::assertCount(0, $header->filter('[data-bar-action="inbox"], a[data-inbox-link]'), 'Inbox is for signed-in players');
        self::assertCount(0, $header->filter('.site-header-account, .nav-account-trigger, .dropdown-menu'), 'The account menu is for signed-in players');
        self::assertNull($header->attr('data-viewer-signed-in'));
        // D5: guests' Home is the homepage
        self::assertSame($router->generate('homepage'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame(['menu', 'search', 'help', 'menu'], $header->filter('[data-bar-action]')->each(static fn (Crawler $tool): string => (string) $tool->attr('data-bar-action')));

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
        self::assertSame(['menu_desk', 'back', 'logo'], array_slice($this->tools($crawler), 0, 3), 'The back button comes right before the logo');

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

        // On both ☰ (desktop and phones)
        $dots = $crawler->filter('.site-header [data-bar-action="menu"] .nav-menu-dot');
        self::assertCount(2, $dots);
        self::assertCount(1, $crawler->filter('.site-header .site-header-menu--desk .nav-menu-dot'));
        self::assertCount(1, $crawler->filter('.site-header .site-header-menu--phone .nav-menu-dot'));
        self::assertSame(['true', 'true'], $dots->each(static fn (Crawler $dot): string => (string) $dot->attr('aria-hidden')));

        // NAV review Q4: the dot is aria-hidden, so the ☰'s name says it - the visible "Menu" stays inside it
        self::assertSame(['Menu, items waiting for review', 'Menu, items waiting for review'], $crawler->filter('.site-header [data-bar-action="menu"]')->each(static fn (Crawler $menu): string => (string) $menu->attr('aria-label')));
        self::assertSame('Menu', trim($crawler->filter('.site-header .site-header-menu--desk .site-header-menu-label')->text()));
    }

    /**
     * NAV review Q4: the name with the dot in every locale - translated, and the ☰'s visible label ("Menu") is part of it
     * (WCAG 2.5.3)
     */
    public function testTheMenuNameWithTheDotKeepsTheLabelInEveryLocale(): void
    {
        $browser = self::createClient();
        NavigationViewer::signIn($browser, NavigationViewer::MODERATOR);
        $router = self::getContainer()->get(RouterInterface::class);
        $names = [];

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $crawler = $browser->request('GET', $router->generate('faq', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            $menu = $crawler->filter('.site-header .site-header-menu--desk');
            self::assertCount(1, $menu->filter('.nav-menu-dot'), $locale);

            $name = (string) $menu->attr('aria-label');
            $label = trim($menu->filter('.site-header-menu-label')->text());
            self::assertNotSame('', $label, $locale);
            self::assertNotSame($label, $name, $locale . ': the name says items are waiting');
            self::assertTrue(str_starts_with($name, $label), $locale . ': "' . $name . '" starts with the visible "' . $label . '"');
            self::assertSame($name, $crawler->filter('.site-header .site-header-menu--phone')->attr('aria-label'), $locale);
            $names[$locale] = $name;
        }

        self::assertCount(6, array_unique($names), 'Translated in every locale');
    }

    /**
     * NAV review Q5: on the `menu` page both ☰ are that page - aria-current="page", no popup and no expanded state (the
     * page's content is the Menu; assets/navigation/nav_layout.js focuses its first row on a click, never a reload) -
     * still links to it without JS. Elsewhere they stay dialog openers.
     */
    public function testTheMenuButtonsOnTheMenuPageAreThatPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', $router->generate('menu'));
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main #site-menu'));

        $menus = $crawler->filter('.site-header [data-bar-action="menu"]');
        self::assertCount(2, $menus);

        foreach ($menus as $menu) {
            assert($menu instanceof DOMElement);
            self::assertSame('page', $menu->getAttribute('aria-current'));
            self::assertFalse($menu->hasAttribute('aria-haspopup'));
            self::assertFalse($menu->hasAttribute('aria-expanded'));
            self::assertSame($router->generate('menu'), $menu->getAttribute('href'));
            self::assertSame('Menu', $menu->getAttribute('aria-label'));
        }

        self::assertSame('site-menu', $menus->first()->attr('aria-controls'));

        // A guest's too (the page stays shared: it depends on the route only)
        self::ensureKernelShutdown();
        $guest = self::createClient();
        $crawler = $guest->request('GET', '/en/menu');
        self::assertSame(['page', 'page'], $crawler->filter('.site-header [data-bar-action="menu"]')->each(static fn (Crawler $menu): string => (string) $menu->attr('aria-current')));

        // Anywhere else no ☰ is the current page
        $crawler = $guest->request('GET', '/en/faq');
        self::assertCount(0, $crawler->filter('.site-header [data-bar-action="menu"][aria-current]'));
        self::assertSame(['dialog', 'dialog'], $crawler->filter('.site-header [data-bar-action="menu"]')->each(static fn (Crawler $menu): string => (string) $menu->attr('aria-haspopup')));
    }

    public function testNoMenuDotForAMemberOrAnOrganiser(): void
    {
        $browser = self::createClient();

        foreach ([NavigationViewer::MEMBER, NavigationViewer::ORGANISER] as $role) {
            NavigationViewer::signIn($browser, $role);

            $crawler = $browser->request('GET', '/en/faq');
            self::assertResponseIsSuccessful($role);
            self::assertCount(0, $crawler->filter('.site-header .nav-menu-dot'), $role);
            self::assertSame(['Menu', 'Menu'], $crawler->filter('.site-header [data-bar-action="menu"]')->each(static fn (Crawler $menu): string => (string) $menu->attr('aria-label')), $role);
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

        self::assertSame(['menu_desk', 'back', 'logo', 'places', 'search', 'pill', 'search_icon', 'add', 'help', 'inbox', 'account', 'menu'], $this->tools($crawler));
        self::assertStringContainsString('site-header--stopwatch', (string) $crawler->filter('.site-header')->attr('class'));
        self::assertSame('/en/stopwatch/' . $id, $crawler->filter('.site-header > .site-header-inner > a[data-stopwatch-pill]')->attr('href'));

        $database->executeStatement('UPDATE stopwatch SET status = :status WHERE id = :id', ['status' => StopwatchStatus::Paused->value, 'id' => $id]);
        $crawler = $browser->request('GET', '/en/faq');

        self::assertSame(['menu_desk', 'back', 'logo', 'places', 'search', 'search_icon', 'add', 'help', 'inbox', 'account', 'menu'], $this->tools($crawler));
        self::assertStringNotContainsString('site-header--stopwatch', (string) $crawler->filter('.site-header')->attr('class'));
    }

    /**
     * NAV-04 (README §4.3 / D16): the desktop tools of a player - + Add (add_entry_url(): a puzzle page's Add carries its
     * puzzle), the 768-1023 px places Home · Puzzles · Community as the top bar's own links (aria-current from the place
     * resolver), the avatar ▾ the only dropdown: no Puzzles ▾ / Community ▾ dropdowns
     */
    public function testTheDesktopToolsOfAPlayer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', $router->generate('hub'));
        self::assertResponseIsSuccessful();
        $header = $crawler->filter('body > header.site-header');

        $add = $header->filter('a.site-header-add');
        self::assertCount(1, $add);
        self::assertSame($router->generate('puzzle_add'), $add->attr('href'));
        self::assertSame('topbar/add/add', $add->attr('data-nav'));
        self::assertSame('Add', trim($add->text()));
        self::assertNull($add->attr('aria-current'), '+ Add is an action, never the current place');

        $nav = $header->filter('nav.site-header-places');
        self::assertCount(1, $nav);
        self::assertSame('Main sections', $nav->attr('aria-label'));
        $places = $nav->filter('a[data-nav-place]');
        self::assertSame(['home', 'puzzles', 'community'], $places->each(static fn (Crawler $place): string => (string) $place->attr('data-nav-place')));
        self::assertSame(
            [$router->generate('hub'), $router->generate('puzzle_library', ['playerId' => self::PLAYER]), $router->generate('community')],
            $places->each(static fn (Crawler $place): string => (string) $place->attr('href')),
        );
        self::assertSame(['topbar/places/home', 'topbar/places/puzzles', 'topbar/places/community'], $places->each(static fn (Crawler $place): string => (string) $place->attr('data-nav')));
        self::assertSame(['Home', 'Puzzles', 'Community'], $places->each(static fn (Crawler $place): string => trim($place->text())));
        self::assertSame('page', $places->first()->attr('aria-current'));
        // The same places as the tab bar's, from the same builder
        self::assertSame(
            $crawler->filter('#bottom-nav a[data-nav-place="community"]')->attr('href'),
            $places->last()->attr('href'),
        );

        // No Puzzles ▾ / Community ▾: no place is a dropdown toggle; the one toggle of the bar is the avatar ▾ (NAV-05)
        self::assertCount(0, $header->filter('.dropdown-toggle, [data-nav-place][data-bs-toggle]'));
        self::assertSame(['topbar/profile/account'], $header->filter('[data-bs-toggle="dropdown"]')->each(static fn (Crawler $toggle): string => (string) $toggle->attr('data-nav')));

        // On a puzzle page + Add carries the puzzle (as the Add tab)
        $crawler = $browser->request('GET', $router->generate('puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]));
        self::assertResponseIsSuccessful();
        self::assertSame($router->generate('puzzle_add', ['puzzleId' => PuzzleFixture::PUZZLE_500_01]), $crawler->filter('.site-header a.site-header-add')->attr('href'));
        self::assertSame($crawler->filter('#bottom-nav a[data-nav-place="add"]')->attr('href'), $crawler->filter('.site-header a.site-header-add')->attr('href'));
    }

    /**
     * Feedback and Inbox show their label from 1200 px (CSS): in every locale it is part of the tool's accessible name
     * (WCAG 2.5.3), and the desktop ☰'s label is its name
     */
    public function testTheDesktopLabelsArePartOfTheToolsNames(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::PLAYER);
        $router = self::getContainer()->get(RouterInterface::class);

        foreach (['en', 'cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $crawler = $browser->request('GET', $router->generate('faq', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);
            $header = $crawler->filter('body > header.site-header');

            foreach (['help', 'inbox'] as $action) {
                $tool = $header->filter(sprintf('[data-bar-action="%s"]', $action));
                $label = trim($tool->filter('.site-header-label')->text());
                self::assertNotSame('', $label, $locale . ' ' . $action);
                self::assertStringContainsString(mb_strtolower($label), mb_strtolower((string) $tool->attr('aria-label')), $locale . ' ' . $action);
            }

            $menu = $header->filter('.site-header-menu--desk');
            self::assertSame((string) $menu->attr('aria-label'), trim($menu->filter('.site-header-menu-label')->text()), $locale);
            self::assertNotSame('', trim($header->filter('.site-header-add-label')->text()), $locale);
        }
    }

    /**
     * Decision 23: a guest's desktop bar has Sign in in the avatar's place - back to this page afterwards, the same link
     * as the Profile tab (SignInLink: the URL only, never the visitor, so guest HTML stays shared); + Add leads to the Add
     * explainer, the places to the homepage, the puzzle database and the Community landing
     */
    public function testAGuestsDesktopBarSignsInBackToThePage(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        $header = $crawler->filter('body > header.site-header');

        $signIn = $header->filter('a.site-header-signin');
        self::assertCount(1, $signIn);
        self::assertSame($router->generate('login', ['return' => '/en/faq']), $signIn->attr('href'));
        self::assertSame($crawler->filter('#bottom-nav a[data-nav-place="profile"]')->attr('href'), $signIn->attr('href'));
        self::assertSame('nofollow', $signIn->attr('rel'));
        self::assertSame('topbar/profile/sign_in', $signIn->attr('data-nav'));
        self::assertSame('Sign in', trim($signIn->text()));

        self::assertSame($router->generate('add_intro'), $header->filter('a.site-header-add')->attr('href'));
        self::assertSame(
            [$router->generate('homepage'), $router->generate('puzzles'), $router->generate('community')],
            $header->filter('nav.site-header-places a[data-nav-place]')->each(static fn (Crawler $place): string => (string) $place->attr('href')),
        );
        self::assertCount(0, $header->filter('[data-bs-toggle="dropdown"], .dropdown-toggle'));

        $response = $browser->getResponse();
        self::assertTrue($response->headers->hasCacheControlDirective('public'), (string) $response->headers->get('Cache-Control'));
        self::assertSame([], $response->headers->getCookies());
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
                str_contains($class, ' site-header-menu--desk ') => 'menu_desk',
                str_contains($class, ' site-header-back ') => 'back',
                str_contains($class, ' site-header-places ') => 'places',
                str_contains($class, ' site-header-add ') => 'add',
                str_contains($class, ' site-header-account ') => 'account',
                str_contains($class, ' site-header-signin ') => 'sign_in',
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
