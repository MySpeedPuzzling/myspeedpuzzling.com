<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\GroupSolvingTimeEdited;
use SpeedPuzzling\Web\Message\ApprovePuzzleMergeRequest;
use SpeedPuzzling\Web\Message\SubmitPuzzleMergeRequest;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenGroupSolvingTimeEdited;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Profiler\Profile;
use Symfony\Component\Messenger\MessageBusInterface;

final class NotificationsControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    // The Inbox badge's parts (INB-02): the old bell shows For you, the old Messages tool the Messages part
    private const string FOR_YOU = '.inbox-badge[data-inbox-part="for_you"]';
    private const string MESSAGES = '.inbox-badge[data-inbox-part="messages"]';

    // A page that marks: the session's user refresh, the viewer's profile row, the Inbox badge, the notifications, the
    // mark's UPDATE in its transaction - the same as before the badge moved ahead of the list (INB-02 review F1)
    private const int MARKING_PAGE_STATEMENTS = 7;

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
     * The fixture player with notifications: a group edit For you, and favorites' times from before INB-03 that are
     * neither counted nor listed (docs/features/navigation INB-03) - they only get marked read with the rest.
     */
    public function testFavoritesTimesDoNotCountInTheBadge(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        // NotificationFixture: three unread favorites' times + the unread group edit
        self::assertSame(4, $this->unreadCount($connection, PlayerFixture::PLAYER_WITH_FAVORITES));

        $crawler = $browser->request('GET', '/en/marketplace');
        $this->assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter(self::FOR_YOU)->text());

        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame(0, $this->unreadCount($connection, PlayerFixture::PLAYER_WITH_FAVORITES));
        // Only the group edit is listed: "edited your shared result", no favorite's "solved puzzle" row
        self::assertCount(1, $crawler->filter('main table tbody tr'));
        self::assertStringContainsString('edited your shared result for', $crawler->filter('main table tbody')->text());
        self::assertStringNotContainsString('solved puzzle', $crawler->filter('main table tbody')->text());
    }

    /**
     * The review's reproduction (INB-02 review F1): a moderator approves the player's merge report keeping the other
     * puzzle, so the reported one is deleted. The approval is counted For you - and now listed too, by the name the
     * request kept - and opening the page clears it: 0 on the next page.
     */
    public function testAnApprovedMergeOfAReportedPuzzleThatWasMergedAwayIsShownAndCleared(): void
    {
        $browser = self::createClient();
        $container = self::getContainer();
        /** @var Connection $connection */
        $connection = $container->get(Connection::class);
        $reportedName = $connection->fetchOne('SELECT name FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]);
        self::assertIsString($reportedName);

        // PLAYER_REGULAR reports PUZZLE_500_05 as a duplicate of PUZZLE_500_04; the admin keeps PUZZLE_500_04
        $mergeRequestId = Uuid::uuid7()->toString();
        $bus = $container->get(MessageBusInterface::class);
        $bus->dispatch(new SubmitPuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            sourcePuzzleId: PuzzleFixture::PUZZLE_500_05,
            reporterId: PlayerFixture::PLAYER_REGULAR,
            duplicatePuzzleIds: [PuzzleFixture::PUZZLE_500_04],
        ));
        $bus->dispatch(new ApprovePuzzleMergeRequest(
            mergeRequestId: $mergeRequestId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            survivorPuzzleId: PuzzleFixture::PUZZLE_500_04,
            mergedName: 'The Kept Puzzle',
            mergedEans: null,
            mergedBrandCodes: null,
            mergedPiecesCount: 500,
            mergedManufacturerId: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            selectedImagePuzzleId: null,
        ));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_500_05]), 'The reported puzzle was merged away');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/faq');
        $this->assertResponseIsSuccessful();
        self::assertSame('1', $crawler->filter(self::FOR_YOU)->text());

        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        $row = $crawler->filter('main table tbody tr.table-active-player');
        self::assertCount(1, $row);
        self::assertStringContainsString($reportedName, $row->text());
        // Linked to the puzzle it became
        self::assertCount(1, $row->filter('a[href="/en/puzzle/' . PuzzleFixture::PUZZLE_500_04 . '"]'));

        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame(0, $this->unreadCount($connection, PlayerFixture::PLAYER_REGULAR));
    }

    /**
     * The page clears whatever the badge counts, listed or not (INB-02 review F1): a lending notification whose transfer
     * is gone (`target_transfer_id` is SET NULL - no code path deletes a transfer today) is counted and can never be
     * listed. The page reads the badge statement first, marks everything read and tells the layout - the same statements
     * as before: the badge, the list, the mark's UPDATE.
     */
    public function testOpeningThePageClearsACountNothingOnItExplains(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at) VALUES (:id, :player, 'PuzzleLentToYou', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame('1', $crawler->filter(self::FOR_YOU)->text());

        $this->startCountingQueries($browser);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('main table tbody tr'), 'Nothing on the page explains the count');
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame('2', $crawler->filter(self::MESSAGES)->text());
        self::assertSame(1, $this->badgeStatements($browser));
        self::assertSame(self::MARKING_PAGE_STATEMENTS, $this->queryCount($browser), "/en/notifications ran:\n" . implode("\n", $this->executedSql($browser)));

        $crawler = $browser->request('GET', '/en/faq');
        self::assertSame('0', $crawler->filter(self::FOR_YOU)->text());
        self::assertSame(0, $this->unreadCount($connection, PlayerFixture::PLAYER_REGULAR));
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
