<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\StopwatchFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * Pages without the bars (docs/features/navigation README §4.10, implementation plan NAV-08): the bottom bar, the Menu
 * element and the rail/column live in {% block nav_chrome %} (+ the Menu shell in nav_layers), which every bar-free
 * template empties together with header and footer - the participants spreadsheet, the round stopwatch, the referees'
 * live results entry, the running stopwatch (ADD-04) and every page inside the app (NAV-14). <body> says so
 * (no-nav-chrome: the desktop column's width stays 0, NAV-13).
 *
 * The sign-in / sign-up pages keep a minimal header - the logo and the language switch - and nothing else: no search
 * field, no Menu, no bars, so the only text inputs on the page are the form's (iOS Password AutoFill on the code page,
 * decision 25; SignInCodeTest).
 */
final class BarFreePagesTest extends WebTestCase
{
    // The new chrome: the top bar, the bottom bar (the rail on desktop, or its own element if R9 ever needs one), the
    // docked column's slot and the Menu, the phone panel's shell, the icon sprite; and the old header rows until NAV-06
    private const array BARS = ['body > header', '.site-header', '#bottom-nav', '.nav-rail', '#menu-slot', '#site-menu', '#menu-layer', '.nav-sprite', '.legacy-chrome'];

    // The sign-in / sign-up pages (templates extending _auth_layout.html.twig) a visitor opens without a pending state
    private const array AUTH_PAGES = ['/login', '/login?return=/en/faq', '/register', '/register?method=email', '/login-link', '/password-reset'];

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function barFreePages(): iterable
    {
        yield 'participants spreadsheet' => ['/en/participants-sheet/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, PlayerFixture::PLAYER_WITH_STRIPE];
        yield 'round stopwatch (guest)' => ['/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, null];
        yield 'round stopwatch (player)' => ['/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, PlayerFixture::PLAYER_REGULAR];
        yield 'live results entry' => ['/en/live-results/' . OfficialResultsFixture::ROUND_GROUP_A, PlayerFixture::PLAYER_WITH_STRIPE];
    }

    #[DataProvider('barFreePages')]
    public function testABarFreePageRendersNoBars(string $url, null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $this->assertNoBars($crawler, $url);
        self::assertCount(0, $crawler->filter('footer.site-footer'), $url);
        // HELP-04: no "Something wrong on this page?" line either
        self::assertCount(0, $crawler->filter('.page-help-line'), $url);
        self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'), $url);
    }

    /**
     * The running stopwatch's first render has no bars (README §4.10; ADD-04 empties the blocks while it runs, "Leave"
     * leads out - StopwatchPageChromeTest). Running is the stopwatch's status: the fixture's lap age does not matter.
     */
    public function testARunningStopwatchRendersNoBars(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/stopwatch/' . StopwatchFixture::STOPWATCH_RUNNING);
        self::assertResponseIsSuccessful();

        $this->assertNoBars($crawler, 'running stopwatch');
        self::assertCount(0, $crawler->filter('footer.site-footer'));
        self::assertCount(0, $crawler->filter('.page-help-line'));
        self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'));
        self::assertCount(1, $crawler->filter('.stopwatch-toolbar a[data-stopwatch-leave]'));
    }

    /**
     * Inside the app every page is bar-free (NAV-14): no web chrome at all, the auth pages' header included, and <body>
     * keeps no room for a column
     */
    public function testTheAppGetsNoBarsAnywhere(): void
    {
        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            foreach (['/en/faq', '/login', '/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION] as $url) {
                $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);

                $crawler = $browser->request('GET', $url);
                self::assertResponseIsSuccessful();

                NavChromeAssertions::assertNoWebChrome($crawler, $name . ' ' . $url);
                $this->assertNoBars($crawler, $name . ' ' . $url);
                self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'), $name . ' ' . $url);

                self::ensureKernelShutdown();
            }
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function authPages(): iterable
    {
        foreach (self::AUTH_PAGES as $url) {
            yield $url => [$url];
        }
    }

    /**
     * Decision 25: the logo (Home) and the language switch - its links keep the destination and say the language
     * (?_locale=, the auth pages carry no locale in the path); no top bar tools, no bottom bar, no Menu. The only form
     * fields on the page are the page's own forms', inside <main>.
     */
    #[DataProvider('authPages')]
    public function testAnAuthPageHasTheMinimalHeaderAndNoOtherInput(string $url): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', $url, server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();

        // One header: the logo and the language switch
        $header = $crawler->filter('body > header');
        self::assertCount(1, $header);
        self::assertSame('site-header site-header--auth', $header->attr('class'));
        self::assertSame($router->generate('homepage'), $header->filter('a.site-header-logo')->attr('href'));
        self::assertSame('topbar/home/logo', $header->filter('a.site-header-logo')->attr('data-nav'));
        self::assertSame('MySpeedPuzzling – Home', $header->filter('a.site-header-logo')->attr('aria-label'));
        self::assertSame('Language: English', $header->filter('details.site-header-language > summary')->attr('aria-label'));
        // NAV review F6: it closes on Esc and on a tap or click outside it, like the old dropdown (the <details> itself
        // works before any script)
        self::assertSame('details-dismiss', $header->filter('details.site-header-language')->attr('data-controller'));

        $languages = $header->filter('.site-header-languages a[data-locale-preference-locale-param]');
        self::assertSame(['de', 'cs', 'fr', 'es', 'ja'], $languages->each(static fn (Crawler $link): string => (string) $link->attr('data-locale-preference-locale-param')));

        foreach ($languages as $link) {
            assert($link instanceof DOMElement);
            $href = $link->getAttribute('href');
            parse_str((string) parse_url($href, PHP_URL_QUERY), $query);
            self::assertSame((string) parse_url($url, PHP_URL_PATH), parse_url($href, PHP_URL_PATH), $href);
            self::assertSame($link->getAttribute('data-locale-preference-locale-param'), $query['_locale'] ?? null, $href);

            if (str_contains($url, 'return=')) {
                self::assertSame('/en/faq', $query['return'] ?? null, $href);
            }
        }

        // Nothing else in it: every link is the logo or a language, no form, no field, no Bootstrap dropdown (plan §3.2)
        self::assertCount(6, $header->filter('a'));
        self::assertCount(0, $header->filter('button, form, input, textarea, select, .dropdown-menu, [data-bs-toggle]'));

        // No bars, no Menu, no search field anywhere
        foreach (['.site-header:not(.site-header--auth)', '#bottom-nav', '.nav-rail', '#menu-slot', '#site-menu', '#menu-layer', '.nav-sprite', '.legacy-chrome', '.site-search', 'input[type="search"]'] as $selector) {
            self::assertCount(0, $crawler->filter($selector), $url . ': ' . $selector);
        }

        // <body> says it has no bars: critical CSS keeps no room for the tab bar on it, also without :has() (NAV review F4)
        self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'));

        // Every field on the page belongs to one of the page's own forms
        $fields = $crawler->filter('input, textarea, select')->count();
        self::assertGreaterThan(0, $fields, $url . ': the page has its form');
        self::assertCount($fields, $crawler->filter('main form input, main form textarea, main form select'), $url . ': a field outside the page\'s forms');

        // The footer stays (its language switch, the legal links)
        self::assertCount(1, $crawler->filter('footer.site-footer'));
    }

    /**
     * A signed-in player on an auth page (the password reset request opens for everybody): the logo is Home = the Hub
     * (D5); still no bars
     */
    public function testASignedInPlayersLogoLeadsToTheHub(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $router = self::getContainer()->get(RouterInterface::class);

        $crawler = $browser->request('GET', '/password-reset', server: ['HTTP_ACCEPT_LANGUAGE' => 'en']);
        self::assertResponseIsSuccessful();

        self::assertSame($router->generate('hub'), $crawler->filter('body > header.site-header--auth a.site-header-logo')->attr('href'));
        self::assertNotNull($crawler->filter('body > header.site-header--auth')->attr('data-viewer-signed-in'));
        self::assertCount(1, $crawler->filter('body > header'));
        self::assertCount(0, $crawler->filter('.site-header:not(.site-header--auth), #bottom-nav, .nav-rail, #menu-slot, #site-menu, #menu-layer, .nav-sprite, .legacy-chrome'));
    }

    private function assertNoBars(Crawler $crawler, string $message): void
    {
        foreach (self::BARS as $selector) {
            self::assertSame(0, NavChromeAssertions::count($crawler, $selector), $message . ': ' . $selector);
        }
    }
}
