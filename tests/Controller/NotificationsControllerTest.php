<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenGroupSolvingTimeEdited;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;

final class NotificationsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    // The Inbox badge's parts (INB-02): the old bell shows For you, the old Messages tool the Messages part
    private const string FOR_YOU = '.inbox-badge[data-inbox-part="for_you"]';
    private const string MESSAGES = '.inbox-badge[data-inbox-part="messages"]';

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
     * The page marks every notification read, so the Inbox badge's For you part shows 0 there - read by the one badge
     * statement after the page marked them, never by a count of the page's own. The Messages part stays: unread
     * messages are not a total of 0 on the notifications page (INB-02, decision 48).
     */
    public function testTheBadgeShowsForYou0AndTheMessagesPartOnThePage(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR: an unread accepted conversation and a pending request (Messages 2)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $this->notifyGroupEdit($connection, PlayerFixture::PLAYER_REGULAR);
        self::assertSame(1, $this->unreadCount($connection, PlayerFixture::PLAYER_REGULAR));

        // Other pages show both parts
        $crawler = $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter(self::FOR_YOU)->text());
        self::assertNull($crawler->filter(self::FOR_YOU)->attr('hidden'));
        self::assertSame('2', $crawler->filter(self::MESSAGES)->text());

        // Unread ones get marked read on the page: For you 0 (hidden), Messages still 2
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertNotNull($crawler->filter(self::FOR_YOU)->attr('hidden'));
        self::assertSame('2', $crawler->filter(self::MESSAGES)->text());
        self::assertNull($crawler->filter(self::MESSAGES)->attr('hidden'));
        self::assertSame(1, $this->badgeStatements($browser));
        self::assertSame(0, $this->unreadCount($connection, PlayerFixture::PLAYER_REGULAR));

        // Nothing unread any more: still one badge statement
        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame(1, $this->badgeStatements($browser));
    }

    /**
     * The fixture player with notifications: a group edit For you, favorites' times that never count.
     */
    public function testFavoritesTimesDoNotCountInTheBadge(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        // Loading the fixture times told the follower about each of them (NotifyWhenPuzzleSolved) - dozens unread
        self::assertGreaterThan(1, $this->unreadCount($connection, PlayerFixture::PLAYER_WITH_FAVORITES));

        $crawler = $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter(self::FOR_YOU)->text());

        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame(0, $this->unreadCount($connection, PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    private function notifyGroupEdit(Connection $connection, string $playerId): void
    {
        // A For you row naming PLAYER_ADMIN, whom nobody blocks
        $connection->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id, actor_player_id) VALUES (:id, :player, 'GroupSolvingTimeEdited', NOW(), :time, :actor)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'time' => PuzzleSolvingTimeFixture::TIME_03, 'actor' => PlayerFixture::PLAYER_ADMIN],
        );
    }

    private function unreadCount(Connection $connection, string $playerId): int
    {
        /** @var int|string $count */
        $count = $connection->fetchOne(
            'SELECT COUNT(*) FROM notification WHERE player_id = :playerId AND read_at IS NULL',
            ['playerId' => $playerId],
        );

        return (int) $count;
    }

    private function badgeStatements(KernelBrowser $browser): int
    {
        $profile = $browser->getProfile();
        self::assertInstanceOf(Profile::class, $profile);

        $collector = $profile->getCollector('db');
        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        /** @var array<string, list<array{sql: string}>> $queriesByConnection */
        $queriesByConnection = $collector->getQueries();
        $notificationCounts = 0;
        $badges = 0;

        foreach ($queriesByConnection as $queries) {
            foreach ($queries as $query) {
                // GetInboxBadge: For you, unread conversations and pending requests in one statement
                if (str_contains($query['sql'], 'AS for_you') && str_contains($query['sql'], 'AS pending_requests')) {
                    $badges++;
                }

                if (str_contains($query['sql'], 'FROM notification') && str_contains($query['sql'], 'COUNT(')) {
                    $notificationCounts++;
                }
            }
        }

        // No separate count of the page or the layout besides the badge
        self::assertSame($badges, $notificationCounts, 'A notification count outside the badge statement');

        return $badges;
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
}
