<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\Navigation\NativeUserAgents;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The navigation launch's 30-day pointer (docs/features/navigation LAUNCH-03, decision 52): "We rearranged the
 * navigation" as a banner at the top of the Hub and a line at the top of the Menu, for 30 days after the launch date,
 * to accounts created before it, until dismissed - one dismissal hides both. Never for guests (guest HTML stays
 * shared), nothing while NavigationLaunch::DATE is null, nothing in the native app. The launch service is replaced
 * with a MockClock and a date (decision R5); the registration date is set here, never the fixtures' load time.
 */
final class NavigationLaunchPointerTest extends WebTestCase
{
    private const string LAUNCH_DAY = '2026-11-02';
    private const string INSIDE = '2026-11-10 12:00:00';
    // Long before the launch day (UTC, as registered_at is stored)
    private const string OLD_ACCOUNT = '2025-05-01 10:00:00';
    private const string HUB_BANNER = 'main .nav-pointer--hub';
    private const string MENU_LINE = '#site-menu > .nav-pointer--menu';

    /**
     * @return iterable<string, array{null|string, string, string, bool}>
     */
    public static function provideMoments(): iterable
    {
        // launch day, now (Prague), registered at => shown
        yield 'an old account inside the 30 days' => [self::LAUNCH_DAY, self::INSIDE, self::OLD_ACCOUNT, true];
        yield 'at midnight of the launch day' => [self::LAUNCH_DAY, '2026-11-02 00:00:00', self::OLD_ACCOUNT, true];
        yield 'the last second of the 30 days' => [self::LAUNCH_DAY, '2026-12-01 23:59:59', self::OLD_ACCOUNT, true];
        yield 'the date not set' => [null, self::INSIDE, self::OLD_ACCOUNT, false];
        yield 'the evening before the launch' => [self::LAUNCH_DAY, '2026-11-01 23:59:59', self::OLD_ACCOUNT, false];
        yield '+30 days' => [self::LAUNCH_DAY, '2026-12-02 00:00:00', self::OLD_ACCOUNT, false];
        yield 'an account created after the date' => [self::LAUNCH_DAY, self::INSIDE, '2026-11-05 09:00:00', false];
    }

    #[DataProvider('provideMoments')]
    public function testThePointer(null|string $launchDay, string $now, string $registeredAt, bool $shown): void
    {
        // PLAYER_REGULAR follows nobody: the launch pointer, unlike the Inbox pointer, is for every old account
        $browser = $this->signedIn($launchDay, $now, PlayerFixture::PLAYER_REGULAR, $registeredAt);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertCount($shown ? 1 : 0, $crawler->filter(self::HUB_BANNER));
        self::assertCount($shown ? 1 : 0, $crawler->filter(self::MENU_LINE));

        // The Menu line is on every page, the banner on the Hub only
        $crawler = $browser->request('GET', '/en/faq');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main .nav-pointer'));
        self::assertCount($shown ? 1 : 0, $crawler->filter(self::MENU_LINE));
    }

    public function testWhatThePointerSaysAndWhereItLeads(): void
    {
        $browser = $this->signedIn(self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_REGULAR, self::OLD_ACCOUNT);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();

        $banner = $crawler->filter(self::HUB_BANNER);
        self::assertStringContainsString('We rearranged the navigation - everything is in the ☰ Menu.', $banner->text());
        $line = $crawler->filter(self::MENU_LINE);
        self::assertStringContainsString('New navigation: everything is in this Menu.', $line->text());
        // At the top of the Menu: before its first group (the phone panel reorders the groups only)
        self::assertCount(1, $crawler->filter('#site-menu > .nav-pointer--menu + section.menu-group'));
        self::assertCount(0, $crawler->filter('#site-menu > section.menu-group ~ .nav-pointer'));

        foreach ([$banner, $line] as $pointer) {
            self::assertSame('/en/where-did-it-go', $pointer->filter('a.nav-pointer-link')->attr('href'));
            self::assertSame('Where did it go?', $pointer->filter('a.nav-pointer-link')->text());
            // One dismissal for both: the existing dismiss-hint controller, one hint type
            self::assertSame('dismiss-hint', $pointer->attr('data-controller'));
            self::assertSame('/en/dismiss-hint', $pointer->attr('data-dismiss-hint-url-value'));
            self::assertSame('navigation_launch_pointer', $pointer->attr('data-dismiss-hint-type-value'));
            $dismiss = $pointer->filter('button.nav-pointer-dismiss');
            self::assertSame('Hide this note', $dismiss->attr('aria-label'));
            self::assertStringContainsString('dismiss-hint#dismiss', (string) $dismiss->attr('data-action'));
        }

        // Instrumented (implementation plan §3.3)
        self::assertSame(['page/home/launch_pointer', 'page/home/launch_pointer_dismiss'], self::navIds($banner));
        self::assertSame(['menu/none/launch_pointer', 'menu/none/launch_pointer_dismiss'], self::navIds($line));
    }

    public function testOneDismissalHidesTheBannerAndTheLine(): void
    {
        $browser = $this->signedIn(self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_REGULAR, self::OLD_ACCOUNT);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(1, $crawler->filter(self::HUB_BANNER));

        // The Inbox pointer's dismissal is another pointer's
        $browser->request('POST', '/en/dismiss-hint', ['type' => 'navigation_inbox_pointer']);
        self::assertResponseStatusCodeSame(204);
        $crawler = $browser->request('GET', '/en/hub');
        self::assertCount(1, $crawler->filter(self::HUB_BANNER));

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'navigation_launch_pointer']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/hub');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::HUB_BANNER));
        self::assertCount(0, $crawler->filter(self::MENU_LINE));
        // The Menu itself is unchanged
        self::assertGreaterThan(5, $crawler->filter('#site-menu section.menu-group')->count());
    }

    /**
     * Guest HTML stays shared-cacheable: no pointer for a guest, the Hub and the FAQ still public - inside the 30 days.
     */
    public function testNeverForAGuest(): void
    {
        $browser = self::createClient();
        $browser->disableReboot();
        self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock(self::INSIDE, 'Europe/Prague'), self::LAUNCH_DAY));

        foreach (['/en/hub', '/en/faq'] as $page) {
            $crawler = $browser->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('.nav-pointer'), $page);
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), $page . ': ' . $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    /**
     * The copy talks about the web's bars: nothing in the native app - not on the Hub, not in the `menu` page's Menu.
     */
    public function testNothingInTheNativeApp(): void
    {
        $browser = $this->signedIn(self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_REGULAR, self::OLD_ACCOUNT);

        $crawler = $browser->request('GET', '/en/hub', server: ['HTTP_USER_AGENT' => NativeUserAgents::IOS]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.nav-pointer'));

        $crawler = $browser->request('GET', '/en/menu', server: ['HTTP_USER_AGENT' => NativeUserAgents::IOS]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('main #site-menu'));
        self::assertCount(0, $crawler->filter('.nav-pointer'));
    }

    private function signedIn(null|string $launchDay, string $now, string $playerId, string $registeredAt): KernelBrowser
    {
        $browser = self::createClient();
        // One container for the whole test, so every request asks the replaced launch service
        $browser->disableReboot();
        self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock($now, 'Europe/Prague'), $launchDay));
        self::getContainer()->get(Connection::class)->executeStatement('UPDATE player SET registered_at = :at WHERE id = :id', ['at' => $registeredAt, 'id' => $playerId]);

        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    /**
     * @return list<string>
     */
    private static function navIds(Crawler $container): array
    {
        $ids = [];

        foreach ($container->filter('a, button') as $element) {
            assert($element instanceof DOMElement);
            $value = $element->getAttribute('data-nav');
            self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, $value);
            $ids[] = $value;
        }

        return $ids;
    }
}
