<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DOMElement;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Navigation\NavigationLaunch;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The Inbox pointer (docs/features/navigation INB-05): "Your favorites' new times are now on Home › Favorites" under the
 * Inbox header on For you, for 30 days after the launch date, to accounts created before it that follow somebody, until
 * dismissed. Nothing while NavigationLaunch::DATE is null. The launch service is replaced with a MockClock and a date
 * (decision R5); the registration date is set here, never the fixtures' load time.
 *
 * Every row of the provider counts the page's statements on the second request (the first marks the fixture's unread
 * notifications read): the same number whether the pointer shows or not - its dismissal and the favorites ride on the
 * viewer's profile row.
 */
final class InboxPointerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string LAUNCH_DAY = '2026-11-02';
    private const string INSIDE = '2026-11-10 12:00:00';
    // Long before the launch day (UTC, as registered_at is stored)
    private const string OLD_ACCOUNT = '2025-05-01 10:00:00';
    private const string POINTER = 'main .inbox-pointer';

    // The session's user refresh, the viewer's profile row, the Inbox badge (ChromeQueryBudgetTest) + the page's
    // notifications - the pointer adds none
    private const int PAGE_STATEMENTS = 4;

    /**
     * @return iterable<string, array{null|string, string, string, string, bool}>
     */
    public static function provideViewers(): iterable
    {
        // launch day, now (Prague), player, registered at => shown
        yield 'an old account that follows somebody' => [self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT, true];
        yield 'the last second of the 30 days' => [self::LAUNCH_DAY, '2026-12-01 23:59:59', PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT, true];
        yield 'the date not set' => [null, self::INSIDE, PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT, false];
        yield 'the evening before the launch' => [self::LAUNCH_DAY, '2026-11-01 23:59:59', PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT, false];
        yield '+30 days' => [self::LAUNCH_DAY, '2026-12-02 00:00:00', PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT, false];
        yield 'an account created after the date' => [self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_WITH_FAVORITES, '2026-11-05 09:00:00', false];
        yield 'an old account that follows nobody' => [self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_REGULAR, self::OLD_ACCOUNT, false];
    }

    #[DataProvider('provideViewers')]
    public function testThePointer(null|string $launchDay, string $now, string $playerId, string $registeredAt, bool $shown): void
    {
        $browser = $this->signedIn($launchDay, $now, $playerId, $registeredAt);

        $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();

        self::assertCount($shown ? 1 : 0, $crawler->filter(self::POINTER));
        self::assertSame(self::PAGE_STATEMENTS, $this->queryCount($browser), "/en/notifications ran:\n" . implode("\n", $this->executedSql($browser)));
    }

    public function testWhatThePointerSaysAndWhereItLeads(): void
    {
        $browser = $this->signedIn(self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT);

        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();

        // Right under the Inbox header
        self::assertCount(1, $crawler->filter('main .inbox-header + .inbox-pointer'));
        $pointer = $crawler->filter(self::POINTER);
        self::assertStringContainsString("Your favorites' new times are now on Home › Favorites.", $pointer->text());

        // To the Hub's Favorites pane (tabs_controller.js shows the pane of the URL's hash)
        $link = $pointer->filter('a.inbox-pointer-link');
        self::assertSame('/en/hub#recent-favorites-activity', $link->attr('href'));
        self::assertSame('Open Favorites', $link->text());

        // Dismissed for good through the existing dismiss-hint controller
        self::assertSame('dismiss-hint', $pointer->attr('data-controller'));
        self::assertSame('/en/dismiss-hint', $pointer->attr('data-dismiss-hint-url-value'));
        self::assertSame('navigation_inbox_pointer', $pointer->attr('data-dismiss-hint-type-value'));
        $dismiss = $pointer->filter('button.inbox-pointer-dismiss');
        self::assertSame('button', $dismiss->attr('type'));
        self::assertSame('Hide this note', $dismiss->attr('aria-label'));
        self::assertStringContainsString('dismiss-hint#dismiss', (string) $dismiss->attr('data-action'));

        // Instrumented like the Inbox header (implementation plan §3.3)
        self::assertSame(['inbox/none/pointer_favorites', 'inbox/none/pointer_dismiss'], self::navIds($pointer));
    }

    public function testGoneAfterTheDismissal(): void
    {
        $browser = $this->signedIn(self::LAUNCH_DAY, self::INSIDE, PlayerFixture::PLAYER_WITH_FAVORITES, self::OLD_ACCOUNT);

        $crawler = $browser->request('GET', '/en/notifications');
        self::assertCount(1, $crawler->filter(self::POINTER));

        $browser->request('POST', '/en/dismiss-hint', ['type' => 'navigation_inbox_pointer']);
        self::assertResponseStatusCodeSame(204);

        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter(self::POINTER));
        // The For you page itself is unchanged
        self::assertCount(1, $crawler->filter('main .inbox-header'));
    }

    private function signedIn(null|string $launchDay, string $now, string $playerId, string $registeredAt): KernelBrowser
    {
        $browser = self::createClient();
        // One container for the whole test, so every request asks the replaced launch service
        $browser->disableReboot();
        self::getContainer()->set(NavigationLaunch::class, new NavigationLaunch(new MockClock($now, 'Europe/Prague'), $launchDay));

        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('UPDATE player SET registered_at = :at WHERE id = :id', ['at' => $registeredAt, 'id' => $playerId]);
        // What the rows mean: PLAYER_WITH_FAVORITES follows two players, PLAYER_REGULAR nobody
        $favorites = $connection->fetchOne('SELECT favorite_players FROM player WHERE id = :id', ['id' => $playerId]);
        self::assertIsString($favorites);
        self::assertSame($playerId === PlayerFixture::PLAYER_WITH_FAVORITES, json_decode($favorites, true) !== []);

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
