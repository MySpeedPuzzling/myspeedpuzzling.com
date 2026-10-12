<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Menu's phone panel shell (docs/features/navigation README §4.12 "Phone panel", MENU-02b; decision 87): one empty
 * <dialog id="menu-layer"> at the end of <body> on every web page - the page's one #site-menu stays in its slot
 * server-side and is moved into the shell only when the panel opens (assets/controllers/menu_layer_controller.js), so
 * the page never holds a second copy. The shell knows the `menu` page in every locale (links to it open the panel)
 * and the page's place (its tab only closes the panel). Shared-cacheable for guests; none in the app and none on the
 * `menu` page (the Menu is its content there). Opened with show(), never showModal(); closed it is display: none.
 */
final class MenuLayerTest extends WebTestCase
{
    public function testGuestPagesCarryTheEmptyShellAndStayShared(): void
    {
        $browser = self::createClient();

        foreach (['/en/faq' => 'none', '/en/ladder' => 'community', '/en/puzzle' => 'puzzles', '/' => 'home'] as $url => $place) {
            $crawler = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($url);

            $shell = $this->shell($crawler, $url);
            self::assertSame($place, $shell->attr('data-menu-layer-place-value'), $url);
            self::assertSame('/', $shell->filter('a.menu-layer-logo')->attr('href'), $url);

            // The one Menu sits in its slot; the shell is empty until the panel opens
            self::assertCount(1, $crawler->filter('#site-menu'), $url);
            self::assertCount(1, $crawler->filter('#menu-slot > #site-menu'), $url);
            self::assertCount(0, $shell->filter('#site-menu'), $url);

            $response = $browser->getResponse();
            self::assertTrue($response->headers->hasCacheControlDirective('public'), $url . ': ' . $response->headers->get('Cache-Control'));
            self::assertSame([], $response->headers->getCookies(), $url);
        }
    }

    public function testThePlayersShellLeadsHomeToTheHub(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        $shell = $this->shell($crawler, '/en/hub');
        self::assertSame('home', $shell->attr('data-menu-layer-place-value'));
        self::assertSame('/en/hub', $shell->filter('a.menu-layer-logo')->attr('href'));
        self::assertCount(1, $crawler->filter('#menu-slot > #site-menu'));
    }

    /**
     * A link to the `menu` page in any locale opens the panel on phones (assets/navigation/menu_links.js)
     */
    public function testTheShellKnowsTheMenuPageInEveryLocale(): void
    {
        $browser = self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        $url = $router->generate('ladder', ['_locale' => 'de']);
        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        $expected = array_map(
            static fn (string $locale): string => $router->generate('menu', ['_locale' => $locale]),
            ['cs', 'en', 'de', 'es', 'fr', 'ja'],
        );

        $paths = json_decode((string) $this->shell($crawler, $url)->attr('data-menu-paths'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame($expected, $paths);
        self::assertContains('/de/menue', $paths);
        self::assertSame('Menü schließen', $crawler->filter('#menu-layer .menu-layer-close')->attr('aria-label'));
        self::assertSame('Menü', trim($crawler->filter('#menu-layer #menu-layer-title')->text()));
    }

    public function testTheMenuPageHasNoShell(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/menu');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('#menu-layer'));
        self::assertCount(1, $crawler->filter('#site-menu'));
    }

    /**
     * Chrome markup rules (implementation plan §3.2): no heading element, no form, only the shell's own class prefix;
     * it comes last in <body> (decision 87: after every GlobalSearch and the dynamic modal)
     */
    public function testTheShellMarkup(): void
    {
        $browser = self::createClient();
        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();

        $shell = $this->shell($crawler, '/en/faq');
        self::assertCount(0, $shell->filter('h1, h2, h3, h4, h5, h6, form, .alert, .card-body, .nav-tabs, .nav-pills, .nav-link'));
        self::assertSame('menu-layer-title', $shell->attr('aria-labelledby'));
        self::assertNull($shell->attr('open'));
        self::assertNull($shell->attr('aria-modal'));
        self::assertCount(1, $shell->filter('.menu-layer-body[data-menu-layer-target="body"] > [data-menu-layer-results][hidden]'));
        self::assertCount(1, $shell->filter('[data-menu-layer-search]'));

        $last = $crawler->filter('body > *')->last();
        self::assertSame('menu-layer', $last->attr('id'));
    }

    public function testTheNativeAppGetsNoShell(): void
    {
        foreach (NativeUserAgents::native() as $name => [$userAgent]) {
            $browser = self::createClient(server: ['HTTP_USER_AGENT' => $userAgent]);
            $crawler = $browser->request('GET', '/en/faq');

            self::assertResponseIsSuccessful($name);
            self::assertSame(0, NavChromeAssertions::count($crawler, '#menu-layer'), $name);
            self::ensureKernelShutdown();
        }
    }

    /**
     * The panel's rules in its script: show(), never showModal() (the bottom bar must stay usable, plan §3.4 item 4);
     * history through assets/layer_history.js only; eager (☰ must answer the first tap)
     */
    public function testTheControllerOpensANonModalDialogThroughTheLayerHelper(): void
    {
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/controllers/menu_layer_controller.js');

        self::assertStringContainsString('this.element.show();', $code);
        self::assertDoesNotMatchRegularExpression('~\.showModal\s*\(~', $code);
        self::assertStringContainsString("import layers from '../layer_history.js';", $code);
        self::assertStringContainsString("const LAYER = 'menu';", $code);
        self::assertStringContainsString('layers.open(LAYER,', $code);
        self::assertStringContainsString('layers.requestClose(LAYER)', $code);
        self::assertStringContainsString('layers.closeForCache()', $code);
        self::assertDoesNotMatchRegularExpression('/stimulusFetch\s*:/', $code);
    }

    /**
     * The shell's CSS (README §4.2, §4.12; F-03 z-index scale in docs/design-system/README.md)
     */
    public function testTheShellsStyles(): void
    {
        $rules = ScssReader::rules((string) file_get_contents(dirname(__DIR__, 2) . '/assets/styles/_nav-menu.scss'));

        $shell = $rules['.menu-layer'] ?? '';
        self::assertMatchesRegularExpression('~position\s*:\s*fixed~', $shell);
        self::assertMatchesRegularExpression('~(?<![\w-])top\s*:\s*0\s*;~', $shell);
        self::assertMatchesRegularExpression('~bottom\s*:\s*var\(--bottom-nav-height~', $shell);
        self::assertMatchesRegularExpression('~z-index\s*:\s*\$z-menu-layer\s*;~', $shell);

        self::assertMatchesRegularExpression('~display\s*:\s*none~', $rules['.menu-layer:not([open])'] ?? '');
        self::assertMatchesRegularExpression('~animation\s*:\s*menu-layer-in 250ms~', $rules['.menu-layer[open]'] ?? '');
        self::assertMatchesRegularExpression('~animation\s*:\s*menu-layer-out 200ms~', $rules['.menu-layer[open].is-closing'] ?? '');
        // The page under the open panel does not scroll; the panel scrolls itself and never hands a scroll on
        self::assertMatchesRegularExpression('~overflow\s*:\s*hidden~', $rules[':root:has(> body > .menu-layer[open])'] ?? '');
        self::assertMatchesRegularExpression('~overscroll-behavior\s*:\s*contain~', $rules['.menu-layer-body'] ?? '');
        self::assertMatchesRegularExpression('~overflow-y\s*:\s*auto~', $rules['.menu-layer-body'] ?? '');

        // Reduced motion: a fade only (no rise)
        $code = (string) file_get_contents(dirname(__DIR__, 2) . '/assets/styles/_nav-menu.scss');
        self::assertMatchesRegularExpression('~@media \(prefers-reduced-motion: reduce\)\s*\{\s*&\[open\]\s*\{\s*animation-name:\s*menu-layer-fade-in;~', $code);
        self::assertMatchesRegularExpression('~@keyframes menu-layer-fade-in\s*\{\s*from\s*\{\s*opacity:\s*0;\s*\}~', $code);
        self::assertMatchesRegularExpression('~@keyframes menu-layer-in\s*\{\s*from\s*\{\s*opacity:\s*0;\s*transform:\s*translateY\(24px\);~', $code);
    }

    private function shell(Crawler $crawler, string $url): Crawler
    {
        $shell = $crawler->filter('dialog#menu-layer.menu-layer[data-controller="menu-layer"]');
        self::assertCount(1, $shell, $url . ': one Menu shell');
        self::assertCount(1, $crawler->filter('#menu-layer'), $url);

        return $shell;
    }
}
