<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Tests\DataFixtures\LentPuzzleTransferFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\Puzzler;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetNotificationsTest extends KernelTestCase
{
    private GetNotifications $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->query = $container->get(GetNotifications::class);
        $this->database = $container->get(Connection::class);
    }

    public function testPuzzleSolvingNotificationContainsFirstAttemptFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === true && $notification->unboxed === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=true and unboxed=false');
    }

    public function testPuzzleSolvingNotificationContainsUnboxedFlag(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->unboxed === true) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with unboxed=true');
    }

    public function testPuzzleSolvingNotificationWithoutFirstAttempt(): void
    {
        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 50);

        $found = false;
        foreach ($notifications as $notification) {
            if ($notification->isPuzzleSolvingNotification() && $notification->firstAttempt === false) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Should find a notification with firstAttempt=false');
    }

    public function testNotificationsAboutAHiddenPlayerAreLeftOutAndTheBadgeAgrees(): void
    {
        // PLAYER_WITH_FAVORITES holds notifications about times of PLAYER_REGULAR and PLAYER_ADMIN
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        $unfiltered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($unfiltered));
        self::assertSame(count($unfiltered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertNotEmpty($filtered);
        self::assertLessThan(count($unfiltered), count($filtered));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($filtered));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $this->targetPlayerIds($filtered));

        self::assertSame(count($filtered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
        self::assertEquals(
            min(array_map(static fn (PlayerNotification $notification): \DateTimeImmutable => $notification->notifiedAt, $filtered)),
            $this->query->getOldestUnreadNotifiedAtForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    public function testNotificationAboutAGroupTimeWithAHiddenMemberIsLeftOut(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR (not hidden) together with PLAYER_PRIVATE (hidden)
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE);

        $before = $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertLessThan($before, count($filtered));
        self::assertSame(count($filtered), $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        foreach ($filtered as $notification) {
            self::assertNotSame(PlayerFixture::PLAYER_PRIVATE, $notification->conversationInitiatorId);
            self::assertFalse(Puzzler::listContainsPlayer($notification->players, PlayerFixture::PLAYER_PRIVATE));
        }
    }

    public function testLendingHistoryWithAHiddenPlayerStaysReadable(): void
    {
        // TRANSFER_06: PLAYER_REGULAR passed a puzzle to PLAYER_WITH_FAVORITES
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $lending = array_values(array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isLendingNotification(),
        ));

        self::assertCount(1, $lending);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $lending[0]->fromPlayerId);
        self::assertSame(
            count($this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100)),
            $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    public function testUnreadCountLeavesReadNotificationsOut(): void
    {
        $before = $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12, read: true);

        self::assertSame($before + 1, $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));

        $this->database->executeStatement('UPDATE notification SET read_at = ' . TestClock::SQL_NOW . ' WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES]);

        self::assertSame(0, $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    public function testUnreadCountCanUseTheUnreadIndex(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->query->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        /** @var list<array{sql: string, params: array<mixed>}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        self::assertCount(1, $executed);

        // The test database is tiny, so the planner would scan it anyway - only prove the index is usable
        $this->database->executeStatement('SET LOCAL enable_seqscan = off');
        /** @var list<string> $plan */
        $plan = $this->database->fetchFirstColumn('EXPLAIN ' . $executed[0]['sql'], array_values($executed[0]['params']));

        self::assertStringContainsString('custom_notification_unread', implode("\n", $plan));
    }

    public function testATeamedUpNotificationNamesTheActorAndTheEventAndAsksToCloseWhileListed(): void
    {
        // John teamed up with Michael, who still has his open team listing at the event
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, actor_player_id, target_partner_listing_id) VALUES (:id, :player, 'PartnerTeamedUp', " . TestClock::SQL_NOW . ", :actor, :listing)",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'actor' => PlayerFixture::PLAYER_REGULAR, 'listing' => PartnerFinderFixture::LISTING_REGULAR_PAIR],
        );

        $notification = $this->partnerNotification(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertTrue($notification->isPartnerTeamedUpNotification());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $notification->targetPlayerId);
        self::assertSame('Partner Finder Open', $notification->partnerCompetitionName);
        self::assertSame(PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN_SLUG, $notification->partnerCompetitionSlug);
        self::assertTrue($notification->partnerReaderListed);

        // A private actor is named but never shown by their picture
        $this->database->executeStatement("UPDATE player SET avatar = 'avatars/john.jpg' WHERE id = :id", ['id' => PlayerFixture::PLAYER_REGULAR]);
        self::assertSame('avatars/john.jpg', $this->partnerNotification(PlayerFixture::PLAYER_WITH_FAVORITES)->targetPlayerAvatar);
        $this->database->executeStatement('UPDATE player SET is_private = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $private = $this->partnerNotification(PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertNull($private->targetPlayerAvatar);
        self::assertTrue($private->targetPlayerIsPrivate);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $private->targetPlayerName);
        $this->database->executeStatement('UPDATE player SET is_private = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $this->database->executeStatement('UPDATE partner_listing SET closed_at = ' . TestClock::SQL_NOW . ' WHERE id = :id', ['id' => PartnerFinderFixture::LISTING_FAVORITES_TEAM]);
        self::assertFalse($this->partnerNotification(PlayerFixture::PLAYER_WITH_FAVORITES)->partnerReaderListed);

        // Blocked by the reader: left out, the badge agrees
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $left = array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isPartnerTeamedUpNotification(),
        );
        self::assertSame([], $left);
    }

    public function testABorrowRequestNotificationNamesTheAskerAndCountsTheAnswers(): void
    {
        // John asks Michael for two puzzles, Michael said yes to one; Michael's notification only
        $requestId = $this->borrowRequest(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_FAVORITES, ['yes', 'waiting']);
        $this->notifyAbout(PlayerFixture::PLAYER_WITH_FAVORITES, 'BorrowRequested', PlayerFixture::PLAYER_REGULAR, $requestId);
        $this->notifyAbout(PlayerFixture::PLAYER_ADMIN, 'BorrowRequested', PlayerFixture::PLAYER_REGULAR, $this->borrowRequest(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN, ['waiting']));

        $borrow = array_values(array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isBorrowRequestNotification(),
        ));

        self::assertCount(1, $borrow);
        self::assertSame($requestId, $borrow[0]->borrowRequestId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $borrow[0]->targetPlayerId);
        self::assertSame(PlayerFixture::PLAYER_REGULAR_NAME, $borrow[0]->targetPlayerName);
        self::assertSame(2, $borrow[0]->borrowItemsTotal);
        self::assertSame(1, $borrow[0]->borrowItemsYes);

        // Blocked by the reader: left out
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
        $left = array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isBorrowRequestNotification(),
        );
        self::assertSame([], $left);
    }

    public function testPagesTogetherAreTheWholeListOnceEach(): void
    {
        $all = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertGreaterThan(2, count($all));

        $paged = [];
        for ($offset = 0; $offset < count($all) + 2; $offset += 2) {
            $page = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 2, $offset);
            self::assertLessThanOrEqual(2, count($page));
            array_push($paged, ...$page);
        }

        self::assertCount(count($all), $paged);
        self::assertEqualsCanonicalizing($this->fingerprints($all), $this->fingerprints($paged));
    }

    public function testAPageIsPickedWithoutTheNotificationsTheReaderDoesNotSee(): void
    {
        // The newest notification is about a player the reader blocks: the first page of one is the next one
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES (:id, :player, 'SubscribedPlayerAddedTime', " . TestClock::SQL_NOW . " + INTERVAL '1 hour', :time)",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'time' => $this->solvingTimeOf(PlayerFixture::PLAYER_REGULAR)],
        );
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $page = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 1);

        self::assertCount(1, $page);
        self::assertNotSame(PlayerFixture::PLAYER_REGULAR, $page[0]->targetPlayerId);
    }

    private function partnerNotification(string $playerId): PlayerNotification
    {
        foreach ($this->query->forPlayer($playerId, 100) as $notification) {
            if ($notification->isPartnerTeamedUpNotification() || $notification->isPartnerGroupMemberNotification()) {
                return $notification;
            }
        }

        self::fail('No partner finder notification.');
    }

    /**
     * @param array<PlayerNotification> $notifications
     * @return list<null|string>
     */
    private function targetPlayerIds(array $notifications): array
    {
        return array_values(array_map(
            static fn (PlayerNotification $notification): null|string => $notification->targetPlayerId,
            $notifications,
        ));
    }

    private function notify(string $playerId, string $type, string $targetColumn, string $targetId, bool $read = false): void
    {
        $readAt = $read ? TestClock::SQL_NOW : 'NULL';
        $now = TestClock::SQL_NOW;

        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, read_at, {$targetColumn}) VALUES (:id, :player, :type, {$now}, {$readAt}, :target)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'target' => $targetId],
        );
    }

    /**
     * @param list<string> $itemStatuses
     */
    private function borrowRequest(string $askerId, string $ownerId, array $itemStatuses): string
    {
        $requestId = Uuid::uuid7()->toString();
        $now = TestClock::SQL_NOW;

        $this->database->executeStatement(
            "INSERT INTO borrow_request (id, send_id, asker_id, owner_id, created_at, expires_at) VALUES (:id, :send, :asker, :owner, {$now}, {$now} + INTERVAL '30 days')",
            ['id' => $requestId, 'send' => Uuid::uuid7()->toString(), 'asker' => $askerId, 'owner' => $ownerId],
        );

        $puzzles = [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_03];
        foreach ($itemStatuses as $index => $status) {
            $this->database->executeStatement(
                'INSERT INTO borrow_request_item (id, request_id, asker_id, owner_id, puzzle_id, status) VALUES (:id, :request, :asker, :owner, :puzzle, :status)',
                ['id' => Uuid::uuid7()->toString(), 'request' => $requestId, 'asker' => $askerId, 'owner' => $ownerId, 'puzzle' => $puzzles[$index], 'status' => $status],
            );
        }

        return $requestId;
    }

    private function notifyAbout(string $playerId, string $type, string $actorId, string $borrowRequestId): void
    {
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, actor_player_id, target_borrow_request_id) VALUES (:id, :player, :type, " . TestClock::SQL_NOW . ", :actor, :request)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'actor' => $actorId, 'request' => $borrowRequestId],
        );
    }

    /**
     * @param array<PlayerNotification> $notifications
     * @return list<string>
     */
    private function fingerprints(array $notifications): array
    {
        return array_values(array_map(
            static fn (PlayerNotification $notification): string => ($notification->notificationType->value ?? '') . '|' . $notification->notifiedAt->format(DATE_ATOM) . '|' . ($notification->targetPlayerId ?? '') . '|' . ($notification->puzzleId ?? ''),
            $notifications,
        ));
    }

    private function solvingTimeOf(string $playerId): string
    {
        $id = $this->database->fetchOne("SELECT id FROM puzzle_solving_time WHERE player_id = :player AND team IS NULL LIMIT 1", ['player' => $playerId]);
        assert(is_string($id));

        return $id;
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
