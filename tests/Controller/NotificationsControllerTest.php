<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NotificationsController;
use SpeedPuzzling\Web\Controller\NotificationsDropdownController;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenGroupSolvingTimeEdited;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class NotificationsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string BADGE = 'a[href="/en/notifications"] .navbar-tool-label';

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/notifications');
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
    }

    /**
     * The page marks every notification read, so the layout's bell badge shows 0 there. The
     * layout used to count the unread notifications again to get that 0 - a second, identical
     * COUNT query on every visit.
     */
    public function testBellBadgeReusesTheCountOfThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $unread = (string) $this->unreadCount($connection);
        self::assertNotSame('0', $unread, 'The player needs unread notifications');

        // Other pages count on their own
        $crawler = $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertSame($unread, $crawler->filter(self::BADGE)->text());

        // Unread ones get marked read on the page - the bell shows 0, as it always did
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::BADGE)->text());
        self::assertSame(1, $this->unreadCountQueries($browser));
        self::assertSame(0, $this->unreadCount($connection));

        // Nothing unread any more: still 0 from a single query
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::BADGE)->text());
        self::assertSame(1, $this->unreadCountQueries($browser));
    }

    private function unreadCount(Connection $connection): int
    {
        /** @var int|string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM notification WHERE player_id = :playerId AND read_at IS NULL',
            ['playerId' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );

        return (int) $count;
    }

    private function unreadCountQueries(KernelBrowser $browser): int
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        /** @var array<string, list<array{sql: string}>> $queriesByConnection */
        $queriesByConnection = $collector->getQueries();
        $count = 0;

        foreach ($queriesByConnection as $queries) {
            foreach ($queries as $query) {
                // GetNotifications::countUnreadForPlayer() - COUNT(*), answered by an index-only scan
                if (str_contains($query['sql'], 'FROM notification') && str_contains($query['sql'], 'COUNT(*)')) {
                    $count++;
                }
            }
        }

        return $count;
    }

    public function testGroupTimeEditIsShownWithTheEditorsName(): void
    {
        $browser = self::createClient();

        // UserBlockFixture has PLAYER_REGULAR blocking the partner, who would then hear nothing of the edit
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM user_block');

        // TIME_12: tracked by PLAYER_REGULAR, edited by the partner PLAYER_PRIVATE (Jane Smith)
        /** @var NotifyWhenGroupSolvingTimeEdited $handler */
        $handler = self::getContainer()->get(NotifyWhenGroupSolvingTimeEdited::class);
        $handler(new GroupSolvingTimeEdited(
            Uuid::fromString(PuzzleSolvingTimeFixture::TIME_12),
            Uuid::fromString(PlayerFixture::PLAYER_PRIVATE),
            [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE],
        ));
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/notifications');

        $this->assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringContainsString('edited your shared result for', $content);
        self::assertStringContainsString('Jane Smith', $content);
    }

    public function testThePageShowsOnePageAndShowMoreLoadsTheNextIntoItsFrame(): void
    {
        $browser = self::createClient();
        $this->addNotifications($browser, PlayerFixture::PLAYER_WITH_FAVORITES, NotificationsController::PAGE_SIZE + 5);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertCount(NotificationsController::PAGE_SIZE, $crawler->filter('.custom-table tbody tr'));
        $frameId = 'notifications-from-' . NotificationsController::PAGE_SIZE;
        self::assertCount(1, $crawler->filter("turbo-frame#{$frameId}"));
        self::assertSame(0, $this->unreadCount($browser->getContainer()->get(Connection::class)));

        $crawler = $browser->request('GET', '/en/notifications?offset=' . NotificationsController::PAGE_SIZE, server: ['HTTP_TURBO_FRAME' => $frameId]);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter("turbo-frame#{$frameId}"));
        self::assertGreaterThanOrEqual(5, $crawler->filter('tbody tr')->count());
        self::assertStringNotContainsString('<html', (string) $browser->getResponse()->getContent());
    }

    public function testTheDropdownShowsTheNewestAndMarksThemRead(): void
    {
        $browser = self::createClient();
        $this->addNotifications($browser, PlayerFixture::PLAYER_WITH_FAVORITES, NotificationsDropdownController::SIZE + 3);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertGreaterThan(0, $this->unreadCount($browser->getContainer()->get(Connection::class)));

        $crawler = $browser->request('GET', '/en/notifications-dropdown', server: ['HTTP_TURBO_FRAME' => 'notifications-dropdown']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#notifications-dropdown'));
        self::assertCount(NotificationsDropdownController::SIZE, $crawler->filter('tbody tr'));
        self::assertCount(NotificationsDropdownController::SIZE, $crawler->filter('tr.table-active-player'));
        self::assertSame(0, $this->unreadCount($browser->getContainer()->get(Connection::class)));
    }

    public function testTheBellShows99PlusFromTheCapOn(): void
    {
        $browser = self::createClient();
        $this->addNotifications($browser, PlayerFixture::PLAYER_WITH_FAVORITES, 120);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/marketplace');

        self::assertResponseIsSuccessful();
        self::assertSame('99+', $crawler->filter(self::BADGE)->text());
    }

    public function testTheDropdownOutsideItsFrameGoesToThePage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/notifications-dropdown');

        self::assertResponseRedirects('/en/notifications');
    }

    public function testTheBellOpensTheDropdown(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/hub');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="notifications-dropdown"] turbo-frame#notifications-dropdown'));
        self::assertCount(1, $crawler->filter('.notifications-dropdown a[href="/en/notifications"]'));
    }

    private function addNotifications(KernelBrowser $browser, string $playerId, int $count): void
    {
        $database = $browser->getContainer()->get(Connection::class);
        $solvingTimeIds = $database->fetchFirstColumn('SELECT id FROM puzzle_solving_time WHERE team IS NULL ORDER BY id LIMIT 5');

        for ($i = 0; $i < $count; $i++) {
            $database->executeStatement(
                "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES (:id, :player, 'SubscribedPlayerAddedTime', " . TestClock::SQL_NOW . " + make_interval(mins => :minutes), :time)",
                ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'minutes' => $i + 1, 'time' => $solvingTimeIds[$i % count($solvingTimeIds)]],
            );
        }
    }
}
