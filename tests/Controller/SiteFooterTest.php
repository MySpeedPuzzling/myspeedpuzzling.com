<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;

/**
 * docs/features/site-footer.md - one footer for everybody, its columns from the navigation registry (FOOT-01); guests
 * also get the newsletter form and "Popular searches", a player only gets a newsletter line after switching the
 * newsletter off.
 */
final class SiteFooterTest extends WebTestCase
{
    private const string PAGE = '/en/faq';
    private const string TURN_ON = '/en/newsletter/turn-on';
    private const string BASELINE_ROUTES = __DIR__ . '/../../docs/features/navigation/delivery/baseline-chrome-routes.json';

    // The links with a "New" badge (the eight of 2026-10-02 + the Community landing), in footer order
    private const array NEW_LINKS = ['/en/what-to-solve-next', '/en/multiscan', '/en/community', '/en/puzzlers', '/en/compare', '/en/pairs-and-teams', '/en/events', '/en/how-progress-works', '/en/achievements'];

    // "<href> <rel>" per column for everybody (navigation README §4.11, implementation plan FOOT-01); a signed-in
    // player's Learn column ends with "Where did it go?". rel="nofollow" where the footer always had it: pages behind sign-in
    // - in guests' HTML only (crawlers never see a signed-in page, SRCH+FOOT review Q2); the Help sheet's opener always
    private const array COLUMNS = [
        ['/en/puzzle -', '/en/marketplace -', '/en/what-to-solve-next -', '/en/puzzle-add nofollow', '/en/stopwatch nofollow', '/en/multiscan nofollow'],
        ['/en/community -', '/en/ladder -', '/en/puzzlers -', '/en/compare nofollow', '/en/pairs-and-teams nofollow', '/en/events -', '/en/recent-activity -'],
        ['/en/getting-started -', '/en/how-progress-works -', '/en/achievements -', '/en/faq -', '/en/guides -', '/en/methodology -'],
        ['/en/puzzle-tracker-app -', '/en/world-jigsaw-puzzle-championship -', '/en/membership nofollow', '/en/feature-requests -', '/en/help?url=/en/faq nofollow', '/en/for-organizers -', '/en/for-developers -'],
    ];

    public function testGuestGetsTheNewsletterFormAndPopularSearches(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $footer = $crawler->filter('footer.site-footer');
        self::assertCount(1, $footer->filter('.footer-newsletter form[action="/en/newsletter/subscribe"]'));
        self::assertCount(4, $footer->filter('.footer-popular details.footer-popular-group'));
        // The columns of navigation FOOT-01 (README §4.11): the places' own names, Learn, the brand
        self::assertSame(['Puzzles', 'Community', 'Learn', 'MySpeedPuzzling'], $footer->filter('.footer-columns h2')->each(static fn (Crawler $heading): string => $heading->text()));
        self::assertCount(0, $footer->filter('.footer-newsletter-off'));
        // A guest page stays shared-cacheable with the footer built from the registry
        self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * The "New" badges stay next to their links (Jan, 2026-10-02) - and the Community landing is new - while the
     * navigation's launch date is not set (NavigationLaunch::DATE null in the delivered code, decision 1 / R1).
     */
    public function testTheNewBadgesShowWhileTheLaunchDateIsNotSet(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $columns = $crawler->filter('footer .footer-columns');
        self::assertSame(self::NEW_LINKS, $columns->filter('a:has(.nav-new)')->extract(['href']));
        self::assertCount(count(self::NEW_LINKS), $columns->filter('.nav-new'));
        self::assertSame(['New'], array_values(array_unique($columns->filter('.nav-new')->each(static fn (Crawler $badge): string => $badge->text()))));
        self::assertCount(0, $crawler->filter('footer .footer-bar .nav-new'), '"All pages" has no badge');
    }

    /**
     * 14 days after the launch day they are gone (decision 1 / R1); until then they show.
     */
    public function testTheNewBadgesExpireFourteenDaysAfterTheLaunch(): void
    {
        foreach (['2026-11-15 23:00:00' => count(self::NEW_LINKS), '2026-11-17 12:00:00' => 0] as $now => $expected) {
            self::ensureKernelShutdown();
            $browser = self::createClient();
            // One container for the whole test, so every request asks the replaced launch service (decision R5)
            $browser->disableReboot();
            self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock($now, 'Europe/Prague'), '2026-11-02'));
            TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

            $crawler = $browser->request('GET', self::PAGE);

            $this->assertResponseIsSuccessful();
            self::assertCount($expected, $crawler->filter('footer .footer-columns .nav-new'), $now);
        }
    }

    /**
     * Every column's links in order: the same for everybody (docs/features/site-footer.md), but "Where did it go?" for
     * signed-in players only (R2). The XP pages follow the XP gate (open in the test environment).
     */
    #[DataProvider('viewers')]
    public function testTheColumnsLinkTheirPagesInOrder(null|string $playerId): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $columns = $crawler->filter('footer .footer-columns > div')->each(static fn (Crawler $column): array => $column->filter('a')->each(
            static fn (Crawler $link): string => sprintf('%s %s', $link->attr('href'), $link->attr('rel') ?? '-'),
        ));
        $expected = self::COLUMNS;

        if ($playerId !== null) {
            $expected[2][] = '/en/where-did-it-go -';
            // A player's HTML is never crawled: no nofollow on the sign-in pages (the Help sheet's opener keeps its own)
            $expected = array_map(static fn (array $links): array => array_map(
                static fn (string $link): string => str_starts_with($link, '/en/help?') ? $link : str_replace(' nofollow', ' -', $link),
                $links,
            ), $expected);
        }

        self::assertSame($expected, $columns);

        // Every link carries its P0-03 data-nav: the one it had before FOOT-01, footer/<column>/<entry> for a new one
        self::assertSame(
            ['footer/discover/puzzles', 'footer/discover/marketplace', 'footer/track/puzzle_picker', 'footer/track/add_time', 'footer/track/stopwatch', 'footer/track/multiscan', 'footer/community/community_place'],
            array_slice($crawler->filter('footer .footer-columns a')->extract(['data-nav']), 0, 7),
        );

        // Send feedback opens the Help sheet, for guests too (they are asked to sign in there)
        $feedback = $crawler->filter('footer .footer-columns a[data-nav="footer/myspeedpuzzling/feedback"]');
        self::assertSame('modal-frame', $feedback->attr('data-turbo-frame'));
        self::assertSame('own', $feedback->attr('data-modal-scrim'));

        // All pages: the Menu as a page, before the legal links
        self::assertSame(['/en/menu', '/en/terms-of-service', '/en/privacy-policy', '/en/data-deletion'], $crawler->filter('footer .footer-bar .footer-legal a')->extract(['href']));
        self::assertSame('All pages', $crawler->filter('footer a[data-nav="footer/bar/all_pages"]')->text());
    }

    /**
     * @return iterable<string, array{null|string}>
     */
    public static function viewers(): iterable
    {
        yield 'guest' => [null];
        yield 'player' => [PlayerFixture::PLAYER_REGULAR];
        yield 'member' => [PlayerFixture::PLAYER_WITH_STRIPE];
    }

    /**
     * Every route today's footer linked for a role before the redesign (delivery/baseline-chrome-routes.json, P0-02) is
     * still linked from it (README §4.11) - `feedback` is now the Help sheet's form endpoint: the footer opens the sheet,
     * whose feedback box posts there (signed-in players; guests are asked to sign in in it).
     */
    #[DataProvider('baselineRoles')]
    public function testEveryRouteOfTheOldFooterIsStillLinked(string $role, null|string $playerId): void
    {
        $baseline = json_decode((string) file_get_contents(self::BASELINE_ROUTES), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($baseline);
        $byRole = $baseline['routes'] ?? null;
        self::assertIsArray($byRole);
        $surfaces = $byRole[$role] ?? null;
        self::assertIsArray($surfaces);
        $routes = $surfaces['footer'] ?? null;
        self::assertIsArray($routes);

        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', self::PAGE);
        $this->assertResponseIsSuccessful();
        $router = self::getContainer()->get(RouterInterface::class);
        $linked = [];

        foreach ($crawler->filter('footer.site-footer a[href^="/"]')->extract(['href']) as $href) {
            assert(is_string($href));

            try {
                $match = $router->match((string) parse_url($href, PHP_URL_PATH));
            } catch (\Throwable) {
                continue;
            }

            $route = $match['_canonical_route'] ?? $match['_route'] ?? null;
            self::assertIsString($route);
            $linked[$route] = $href;
        }

        foreach ($routes as $route) {
            self::assertIsString($route);

            if ($route === 'feedback') {
                self::assertArrayHasKey('help', $linked, $role . ': Send feedback opens the Help sheet');
                self::assertStringStartsWith('/en/help?url=', $linked['help']);

                if ($playerId !== null) {
                    $sheet = $browser->request('GET', $linked['help'], server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
                    self::assertCount(1, $sheet->filter('form[action="/en/feedback"]'), $role . ': the sheet\'s feedback box posts to feedback');
                }

                continue;
            }

            self::assertArrayHasKey($route, $linked, sprintf('%s: the footer no longer links %s', $role, $route));
        }
    }

    /**
     * @return iterable<string, array{string, null|string}>
     */
    public static function baselineRoles(): iterable
    {
        yield 'guest' => ['guest', null];
        yield 'player' => ['player', PlayerFixture::PLAYER_PRIVATE];
        yield 'member' => ['member', PlayerFixture::PLAYER_WITH_STRIPE];
    }

    public function testEachPageIsLinkedOnceFromTheFooter(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        // The newsletter consent links the privacy policy in its text as well - that one is meant
        $hrefs = $crawler
            ->filter('footer .footer-main a[href^="/"], footer .footer-popular a[href^="/"], footer .footer-bar a[href^="/"]')
            ->each(static fn (Crawler $link): string => (string) $link->attr('href'));
        self::assertGreaterThan(40, count($hrefs));
        self::assertSame($hrefs, array_values(array_unique($hrefs)));
    }

    public function testFooterSwitchesToTheOtherLanguages(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::PAGE);

        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        $toggle = $crawler->filter('footer .footer-language-toggle');
        self::assertSame('Language: English', $toggle->attr('aria-label'));
        self::assertSame(
            array_map(static fn (string $locale): string => $urlGenerator->generate('faq', ['_locale' => $locale]), ['de', 'cs', 'fr', 'es', 'ja']),
            $crawler->filter('footer .footer-language .dropdown-item')->extract(['href']),
        );
    }

    public function testSubscribedPlayerGetsNoNewsletterElement(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', self::PAGE);

        $this->assertResponseIsSuccessful();
        $footer = $crawler->filter('footer.site-footer');
        self::assertCount(4, $footer->filter('.footer-columns h2'));
        self::assertCount(0, $footer->filter('.footer-newsletter'));
        self::assertCount(0, $footer->filter('.footer-newsletter-off'));
        self::assertCount(0, $footer->filter('.footer-popular'));
    }

    public function testPlayerWhoSwitchedTheNewsletterOffTurnsItBackOnFromTheFooter(): void
    {
        $browser = $this->playerWithNewsletterOff();

        $crawler = $browser->request('GET', self::PAGE);
        self::assertCount(1, $crawler->filter('footer form.footer-newsletter-off[action="' . self::TURN_ON . '"]'));

        $this->turnOn($browser, 'http://localhost');

        $this->assertResponseRedirects('http://localhost' . self::PAGE);
        self::assertTrue($this->newsletterEnabled($browser));

        $crawler = $browser->followRedirect();
        self::assertStringContainsString('The monthly news e-mail is on.', $crawler->filter('.alert')->text());
        self::assertCount(0, $crawler->filter('footer .footer-newsletter-off'));
    }

    public function testForgedRequestChangesNothing(): void
    {
        $browser = $this->playerWithNewsletterOff();

        $this->turnOn($browser, 'https://evil.example');

        $this->assertResponseRedirects();
        self::assertFalse($this->newsletterEnabled($browser));
    }

    public function testGuestIsSentToSignIn(): void
    {
        $browser = self::createClient();

        $this->turnOn($browser, 'http://localhost');

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    private function playerWithNewsletterOff(): KernelBrowser
    {
        $browser = self::createClient();
        $browser->getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET newsletter_enabled = false WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        return $browser;
    }

    private function turnOn(KernelBrowser $browser, string $origin): void
    {
        // Stateless CSRF: any token, as long as the request comes from our own origin
        $browser->request('POST', self::TURN_ON, ['_token' => 'csrf-token'], server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_REFERER' => $origin . self::PAGE,
        ]);
    }

    private function newsletterEnabled(KernelBrowser $browser): bool
    {
        return (bool) $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT newsletter_enabled FROM player WHERE id = :id',
            ['id' => PlayerFixture::PLAYER_REGULAR],
        );
    }
}
