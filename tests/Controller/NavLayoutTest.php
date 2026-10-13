<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The desktop Menu (docs/features/navigation README §4.3 / D16, implementation plan NAV-13) in the markup: what CSS
 * docks, folds and moves is server-rendered once and the same for every visitor of a URL - the rail is #bottom-nav
 * (a guest's Profile carries the rail's "Sign in"), the docked column is the page's one #site-menu in its slot (the
 * dock-only rows, the disclosure buttons, the script that marks the folded groups and scrolls the column before paint),
 * the overlay is the empty shell #menu-layer; the desktop ☰ knows what it controls and the words of its tooltip. The
 * state lives only in the client (localStorage, html attributes) - guest HTML stays shared-cacheable. Pages without the
 * bars (and the app) say so on <body> (no-nav-chrome: no column width), the `menu` page too (no-nav-column: the Menu is
 * its content, the rail stays).
 */
final class NavLayoutTest extends WebTestCase
{
    private const array FOLD = [
        'en' => ['Collapse the menu', 'Expand the menu'],
        'cs' => ['Sbalit menu', 'Rozbalit menu'],
        'de' => ['Menü einklappen', 'Menü ausklappen'],
        'es' => ['Contraer el menú', 'Expandir el menú'],
        'fr' => ['Replier le menu', 'Déplier le menu'],
        'ja' => ['メニューを折りたたむ', 'メニューを展開する'],
    ];

    public function testAPlayersPageCarriesTheColumnTheRailAndTheOverlayShell(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        // One Menu, in its slot - the column; the rail is the bar; the overlay is the empty shell at the end of <body>
        self::assertCount(1, $crawler->filter('#site-menu'));
        self::assertCount(1, $crawler->filter('body > #menu-slot > #site-menu'));
        self::assertCount(1, $crawler->filter('body > nav#bottom-nav'));
        self::assertCount(1, $crawler->filter('body > dialog#menu-layer'));
        self::assertCount(0, $crawler->filter('#menu-layer #site-menu'));

        $body = (string) $crawler->filter('body')->attr('class');
        self::assertStringNotContainsString('no-nav-chrome', $body);
        self::assertStringNotContainsString('no-nav-column', $body);

        // The column starts with the places (README §4.3): Home · Puzzles · Community - each headed by its place row
        $dockOrder = $crawler->filter('#site-menu > section')->each(static fn (Crawler $section): string => (string) $section->attr('data-menu-group'));
        self::assertSame(['home', 'puzzles', 'community'], array_slice($dockOrder, 0, 3));
        self::assertCount(1, $crawler->filter('#site-menu [data-menu-group="home"] a.mr.mr--place[data-nav="menu/home/home"]'));

        // Group headings are disclosure buttons (expanded server-side - the client marks the folded ones before paint),
        // never on the place groups (decision 30); Your puzzling is headed by its Profile row in the column
        $disclosures = $crawler->filter('#site-menu .menu-disclosure');
        self::assertGreaterThan(3, $disclosures->count());

        foreach ($disclosures as $button) {
            assert($button instanceof DOMElement);
            self::assertSame('true', $button->getAttribute('aria-expanded'));
            self::assertCount(1, $crawler->filter('#' . $button->getAttribute('aria-controls')), 'controls an existing list');
            self::assertMatchesRegularExpression('~^menu/[a-z_]+/disclosure$~', $button->getAttribute('data-nav'));
            $group = $button->parentNode;
            assert($group instanceof DOMElement);
            self::assertNotContains($group->getAttribute('data-menu-group'), ['home', 'puzzles', 'community']);
        }

        self::assertCount(1, $crawler->filter('#site-menu section.menu-group--by-place[data-menu-group="puzzling"] > .menu-disclosure'));
        self::assertCount(1, $crawler->filter('#site-menu [data-menu-group="puzzling"] li.menu-only-dock a.mr--place'));

        // The script right after the Menu (the folded groups' buttons and the column's scroll, before paint): the same
        // text on every page, never the browser's scroll-into-view
        $script = $crawler->filter('#menu-slot > #site-menu + script');
        self::assertCount(1, $script);
        self::assertStringContainsString("sessionStorage.getItem('msp:nav-col-scroll')", $script->text());
        self::assertStringContainsString("getAttribute('data-nav-collapsed')", $script->text());

        // The desktop ☰ controls the Menu, its tooltip words come with it (assets/navigation/nav_layout.js shows them)
        $burger = $crawler->filter('header.site-header .site-header-menu--desk');
        self::assertSame('site-menu', $burger->attr('aria-controls'));
        self::assertSame('Collapse the menu', $burger->attr('data-nav-fold-label'));
        self::assertSame('Expand the menu', $burger->attr('data-nav-dock-label'));

        // No rail label for players: their Profile is their profile in the rail too
        self::assertCount(0, $crawler->filter('#bottom-nav .nav-rail-label, #bottom-nav .has-rail-label'));

        // The client's state never comes from the server
        self::assertNull($crawler->filter('html')->attr('data-nav-layout'));
        self::assertNull($crawler->filter('html')->attr('data-nav-collapsed'));
    }

    public function testTheHeadScriptSetsTheLayoutBeforePaint(): void
    {
        $browser = self::createClient();
        $html = (string) $browser->request('GET', '/en/faq')->html();
        $head = substr($html, 0, (int) strpos($html, '</head>'));

        // The build of assets/head/nav_layout.js (templates/_inline_scripts, InlineScriptsBuildTest) - it reads the choice
        $script = '<script>' . file_get_contents(__DIR__ . '/../../templates/_inline_scripts/nav_layout.js') . '</script>';
        self::assertStringContainsString("getItem('msp:nav-layout')", $script);
        self::assertStringContainsString($script, $head);
        self::assertLessThan(strpos($head, '<style>'), strpos($head, $script), 'before the critical CSS (implementation plan §3.11)');
    }

    /**
     * README §4.3: the rail shows the places but Add (the top bar's + Add); a guest's Profile reads "Sign in" there - the
     * phone tab bar keeps "Profile". Guest pages stay public (Hub and a puzzle page, the Verify's two).
     */
    public function testGuestsGetTheRailsSignInAndStayShared(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);

        foreach (['/en/hub', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01] as $url) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            $profile = $crawler->filter('#bottom-nav a.nav-tab--profile');
            self::assertStringContainsString('has-rail-label', (string) $profile->attr('class'), $url);
            self::assertSame('Profile', $profile->filter('.nav-tab-label')->text());
            self::assertSame('Sign in', $profile->filter('.nav-rail-label')->text());
            self::assertSame($router->generate('login', ['return' => $url]), $profile->attr('href'));

            // The guests' card follows the places in the column (decision 31)
            $dockOrder = $crawler->filter('#site-menu > section')->each(static fn (Crawler $section): string => (string) $section->attr('data-menu-group'));
            self::assertSame(['home', 'puzzles', 'community', 'you'], array_slice($dockOrder, 0, 4), $url);
            self::assertCount(1, $crawler->filter('#site-menu'), $url);

            $response = $browser->getResponse();
            self::assertTrue($response->headers->hasCacheControlDirective('public'), $url . ': ' . $response->headers->get('Cache-Control'));
            self::assertSame([], $response->headers->getCookies(), $url);
        }
    }

    public function testTheMenuButtonsTooltipInEveryLocale(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        $translator = self::getContainer()->get(TranslatorInterface::class);

        foreach (self::FOLD as $locale => [$fold, $dock]) {
            $crawler = $browser->request('GET', $router->generate('faq', ['_locale' => $locale]));
            self::assertResponseIsSuccessful($locale);

            $burger = $crawler->filter('header.site-header .site-header-menu--desk');
            self::assertSame($fold, $burger->attr('data-nav-fold-label'), $locale);
            self::assertSame($dock, $burger->attr('data-nav-dock-label'), $locale);
            self::assertSame($fold, $translator->trans('nav.layout.fold', [], null, $locale));

            if ($locale !== 'en') {
                self::assertNotSame(self::FOLD['en'][0], $fold, $locale);
                self::assertNotSame(self::FOLD['en'][1], $dock, $locale);
            }
        }
    }

    /**
     * The `menu` page shows the Menu as its content: no slot, no shell - <body> says so, and the desktop keeps the rail
     */
    public function testTheMenuPageKeepsTheRailInsteadOfTheColumn(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/menu');
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('no-nav-column', (string) $crawler->filter('body')->attr('class'));
        self::assertStringNotContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'));
        self::assertCount(0, $crawler->filter('#menu-slot, #menu-layer'));
        self::assertCount(1, $crawler->filter('main #site-menu'));
        self::assertCount(1, $crawler->filter('body > nav#bottom-nav'));
    }

    /**
     * Pages without the bars - and every page inside the app - have no column and no rail: <body> carries no-nav-chrome
     * (critical CSS keeps --nav-left at 0 there). The bar-free templates set nav_chrome_off (NavLayoutCssTest lists them).
     */
    public function testTheAppHasNoColumnWidth(): void
    {
        $browser = self::createClient();

        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            $crawler = $browser->request('GET', '/en/faq', server: ['HTTP_USER_AGENT' => $userAgent]);
            self::assertResponseIsSuccessful($name);
            self::assertStringContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'), $name);
            self::assertCount(0, $crawler->filter('#menu-slot, #bottom-nav, #menu-layer'), $name);
        }

        $crawler = $browser->request('GET', '/en/faq');
        self::assertStringNotContainsString('no-nav-chrome', (string) $crawler->filter('body')->attr('class'));
    }
}
