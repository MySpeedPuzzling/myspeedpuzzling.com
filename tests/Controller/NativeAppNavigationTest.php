<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\Navigation\NavChromeAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Native-ready navigation (docs/features/navigation NAV-14, README §4.14): inside the Hotwire Native app the page says
 * so on <html data-native-app>, renders no web chrome and is never shared-cacheable; a browser gets none of that.
 * Every layer is also a plain route answering a full page in the app; layer openers inside content are plain links
 * there (layer_frame() = _top). Rows added later: `menu` rows (MENU-01/02b), the help sheet (HELP-01b), the bars' roots
 * (NAV-01, NAV-03, NAV-04, NAV-13) through NavChromeAssertions::WEB_CHROME_SELECTORS, `data-bar-action` (NAV-03/04).
 */
final class NativeAppNavigationTest extends WebTestCase
{
    private const string PUZZLE_PAGE = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;

    /**
     * @return array<string, array{string, string}>
     */
    public static function nativeUserAgents(): array
    {
        return NativeUserAgents::native();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nativeUserAgentsOnly(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], NativeUserAgents::native());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function webUserAgents(): array
    {
        return NativeUserAgents::web();
    }

    #[DataProvider('nativeUserAgents')]
    public function testGuestPagesInTheAppHaveTheFlagNoChromeAndAreNotShared(string $userAgent, string $platform): void
    {
        $browser = self::createClient();

        foreach (['/en/faq', self::PUZZLE_PAGE] as $url) {
            $crawler = $browser->request('GET', $url, server: ['HTTP_USER_AGENT' => $userAgent]);

            self::assertResponseIsSuccessful($url);
            self::assertSame($platform, $crawler->filter('html')->attr('data-native-app'), $url);
            NavChromeAssertions::assertNoWebChrome($crawler, $url);
            self::assertCount(1, $crawler->filter('main'), $url);
            self::assertNotShared($browser, $url);
        }
    }

    #[DataProvider('nativeUserAgents')]
    public function testTheMembersHubInTheAppHasTheFlagAndNoChrome(string $userAgent, string $platform): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MEMBER);

        $crawler = $browser->request('GET', '/en/hub', server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertResponseIsSuccessful();
        self::assertSame($platform, $crawler->filter('html')->attr('data-native-app'));
        NavChromeAssertions::assertNoWebChrome($crawler);
        self::assertNotShared($browser, '/en/hub');
    }

    #[DataProvider('webUserAgents')]
    public function testBrowsersGetTheChromeWithoutTheFlag(string $userAgent): void
    {
        $browser = self::createClient();

        foreach (['/en/faq', self::PUZZLE_PAGE] as $url) {
            $crawler = $browser->request('GET', $url, server: ['HTTP_USER_AGENT' => $userAgent]);

            self::assertResponseIsSuccessful($url);
            self::assertNull($crawler->filter('html')->attr('data-native-app'), $url);
            NavChromeAssertions::assertWebHeaderAndFooter($crawler, $url);
            self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'), $url);
        }

        TestingLogin::asPlayer($browser, self::MEMBER);
        $crawler = $browser->request('GET', '/en/hub', server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertResponseIsSuccessful();
        self::assertNull($crawler->filter('html')->attr('data-native-app'));
        NavChromeAssertions::assertWebHeaderAndFooter($crawler);
    }

    /**
     * Layers (README §4.14 (a)) as plain routes in the app: a full page with their content, no web chrome. `help`
     * (HELP-01a) and the `menu` page (MENU-02a; its positive `main #site-menu` row comes with MENU-01) included.
     */
    #[DataProvider('nativeUserAgents')]
    public function testEveryLayerIsAPlainRouteInTheApp(string $userAgent, string $platform): void
    {
        $browser = self::createClient();

        // Guests: the player card and an achievement's page - public pages for a browser, never for the app
        $layers = [
            'player_card' => ['/en/puzzler-card/' . PlayerFixture::PLAYER_REGULAR, 'h1.players-card-page-title', '#players-card-name'],
            'achievement_detail' => ['/en/achievements/puzzles_solved', 'main h1', '#tier-1'],
            // The Help sheet's page: its rows in <main>
            'help' => ['/en/help', 'main h1', 'main [data-help-sheet]'],
            // The Menu's page (skeleton: heading and intro)
            'menu' => ['/en/menu', 'main h1', 'main .menu-page'],
        ];

        foreach ($layers as $route => [$url, $title, $content]) {
            $crawler = $this->layerPage($browser, $url, $userAgent, $platform, $route);
            self::assertCount(1, $crawler->filter($title), $route);
            self::assertCount(1, $crawler->filter($content), $route);
        }

        // The Inbox tabs: a member's
        TestingLogin::asPlayer($browser, self::MEMBER);

        // One Inbox header on both (INB-01): the H1 "Inbox", the open tab marked
        $crawler = $this->layerPage($browser, '/en/notifications', $userAgent, $platform, 'notifications');
        self::assertSame('Inbox', trim($crawler->filter('main h1')->text()));
        self::assertSame('inbox/none/for_you', $crawler->filter('main .inbox-tab[aria-current="page"]')->attr('data-nav'));

        $crawler = $this->layerPage($browser, '/en/messages', $userAgent, $platform, 'conversations_list');
        self::assertSame('Inbox', trim($crawler->filter('main h1')->text()));
        self::assertSame('inbox/none/messages', $crawler->filter('main .inbox-tab[aria-current="page"]')->attr('data-nav'));
    }

    /**
     * The achievement sheet is a modal-frame fragment: opened as a page (the app's links are _top) it answers with
     * the achievement's own page
     */
    #[DataProvider('nativeUserAgentsOnly')]
    public function testTheAchievementSheetSendsTheAppToTheAchievementsPage(string $userAgent): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/achievements/puzzles_solved/info', server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertResponseRedirects('/en/achievements/puzzles_solved', 302);
    }

    public function testAchievementOpenersAreSheetsOnTheWebAndPlainLinksInTheApp(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, self::MEMBER);
        $page = '/en/player-achievements/' . self::MEMBER;
        $openers = 'a[href*="/en/achievements/"][href*="/info"]';

        $crawler = $browser->request('GET', $page, server: ['HTTP_USER_AGENT' => NativeUserAgents::PIXEL_CHROME]);
        self::assertResponseIsSuccessful();
        $web = $crawler->filter($openers);
        self::assertGreaterThan(0, $web->count());
        self::assertCount($web->count(), $crawler->filter($openers . '[data-turbo-frame="modal-frame"]'));

        foreach (NativeUserAgents::native() as [$userAgent]) {
            $crawler = $browser->request('GET', $page, server: ['HTTP_USER_AGENT' => $userAgent]);
            self::assertResponseIsSuccessful();
            $app = $crawler->filter($openers);

            self::assertCount($web->count(), $app);
            self::assertCount($app->count(), $crawler->filter($openers . '[data-turbo-frame="_top"]'));
            self::assertCount(0, $crawler->filter('[data-turbo-frame="modal-frame"][href*="/info"]'));
        }
    }

    private function layerPage(KernelBrowser $browser, string $url, string $userAgent, string $platform, string $route): Crawler
    {
        $crawler = $browser->request('GET', $url, server: ['HTTP_USER_AGENT' => $userAgent]);

        self::assertResponseIsSuccessful($route);
        self::assertSame($platform, $crawler->filter('html')->attr('data-native-app'), $route);
        NavChromeAssertions::assertNoWebChrome($crawler, $route);
        self::assertNotShared($browser, $route);

        return $crawler;
    }

    private static function assertNotShared(KernelBrowser $browser, string $what): void
    {
        $cacheControl = (string) $browser->getResponse()->headers->get('Cache-Control');

        self::assertStringNotContainsString('public', $cacheControl, $what . ': ' . $cacheControl);
        self::assertStringNotContainsString('s-maxage', $cacheControl, $what . ': ' . $cacheControl);
        self::assertStringNotContainsString('User-Agent', (string) $browser->getResponse()->headers->get('Vary'), $what);
    }
}
