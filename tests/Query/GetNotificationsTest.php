<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetInboxBadge;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\LentPuzzleTransferFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\NotificationType;
use SpeedPuzzling\Web\Value\Puzzler;
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

    /**
     * A notification about a solving time carries the time's flags. Since favorites' times left the list (INB-03) that
     * is a group edit; the flags come from the same branch of the query.
     */
    public function testPuzzleSolvingNotificationContainsFirstAttemptFlag(): void
    {
        // TIME_01: firstAttempt=true, unboxed=false
        $notification = $this->groupEditOf(PuzzleSolvingTimeFixture::TIME_01);

        self::assertTrue($notification->isPuzzleSolvingNotification());
        self::assertTrue($notification->firstAttempt);
        self::assertFalse($notification->unboxed);
    }

    /**
     * The result a solving-time notification is about rides along in the same statement - the player card's context of
     * the row (docs/features/navigation CARD-01b); every other kind has none
     */
    public function testASolvingTimeNotificationCarriesItsTimeForThePlayerCard(): void
    {
        $notification = $this->groupEditOf(PuzzleSolvingTimeFixture::TIME_01);
        self::assertSame(PuzzleSolvingTimeFixture::TIME_01, $notification->targetTimeId);

        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        $lending = array_values(array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100),
            static fn (PlayerNotification $notification): bool => $notification->isLendingNotification(),
        ));
        self::assertCount(1, $lending);
        self::assertNull($lending[0]->targetTimeId);
    }

    public function testPuzzleSolvingNotificationContainsUnboxedFlag(): void
    {
        $notification = $this->groupEditOf(PuzzleSolvingTimeFixture::TIME_45_UNBOXED);

        self::assertTrue($notification->isPuzzleSolvingNotification());
        self::assertTrue($notification->unboxed);
    }

    public function testPuzzleSolvingNotificationWithoutFirstAttempt(): void
    {
        // TIME_07: firstAttempt=false
        $notification = $this->groupEditOf(PuzzleSolvingTimeFixture::TIME_07);

        self::assertTrue($notification->isPuzzleSolvingNotification());
        self::assertFalse($notification->firstAttempt);
    }

    /**
     * Favorites' times left the bell (docs/features/navigation INB-03, README D4): the SubscribedPlayerAddedTime rows
     * that exist - NotificationFixture keeps three unread ones from before - are neither listed nor counted, a new one
     * changes nothing, and the oldest unread notification is one the list shows.
     */
    public function testFavoritesTimesAreNeitherListedNorCounted(): void
    {
        self::assertSame(3, $this->unreadOfType(PlayerFixture::PLAYER_WITH_FAVORITES, NotificationType::SubscribedPlayerAddedTime));

        $listed = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertNotEmpty($listed);
        self::assertSame([], array_values(array_filter(
            $listed,
            static fn (PlayerNotification $notification): bool => $notification->notificationType === NotificationType::SubscribedPlayerAddedTime,
        )));
        $badge = $this->badgeForYou([]);
        self::assertSame($this->unreadForYou($listed), $badge);
        $oldest = $this->query->getOldestUnreadNotifiedAtForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);
        $unreadListed = array_map(
            static fn (PlayerNotification $notification): \DateTimeImmutable => $notification->notifiedAt,
            array_filter($listed, static fn (PlayerNotification $notification): bool => $notification->readAt === null),
        );
        self::assertNotEmpty($unreadListed);
        self::assertEquals(min($unreadListed), $oldest);

        // A favorite's time noted a month ago and unread: still nothing
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES (:id, :player, 'SubscribedPlayerAddedTime', NOW() - INTERVAL '30 days', :time)",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'time' => PuzzleSolvingTimeFixture::TIME_03],
        );

        self::assertEquals($listed, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100));
        self::assertSame($badge, $this->badgeForYou([]));
        self::assertEquals($oldest, $this->query->getOldestUnreadNotifiedAtForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    /**
     * A message request stays listed in For you (decision 49) but counts once, under Messages: the badge's For you part
     * leaves its NewConversationRequest row out, the Messages part holds the pending request.
     */
    public function testAMessageRequestIsListedButCountedOnlyUnderMessages(): void
    {
        // CONVERSATION_PENDING: PLAYER_WITH_STRIPE asks PLAYER_REGULAR
        $badge = self::getContainer()->get(GetInboxBadge::class);
        $before = $badge->forPlayer(PlayerFixture::PLAYER_REGULAR, []);
        $this->notify(PlayerFixture::PLAYER_REGULAR, 'NewConversationRequest', 'target_conversation_id', ConversationFixture::CONVERSATION_PENDING);

        $requests = array_values(array_filter(
            $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, 100),
            static fn (PlayerNotification $notification): bool => $notification->isConversationRequestNotification(),
        ));
        self::assertCount(1, $requests);
        self::assertSame(ConversationFixture::CONVERSATION_PENDING, $requests[0]->conversationId);

        $after = $badge->forPlayer(PlayerFixture::PLAYER_REGULAR, []);
        self::assertEquals($before, $after);
        self::assertSame(0, $after->forYou);
        self::assertSame(1, $after->pendingRequests);
    }

    public function testNotificationsAboutAHiddenPlayerAreLeftOutAndTheBadgeAgrees(): void
    {
        // PLAYER_WITH_FAVORITES holds notifications about times of PLAYER_REGULAR (NotificationFixture's group edit by
        // the regular player) and PLAYER_ADMIN (a group edit by the admin on their TIME_03)
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_03, actorId: PlayerFixture::PLAYER_ADMIN);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        $unfiltered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($unfiltered));
        self::assertSame($this->unreadForYou($unfiltered), $this->badgeForYou([]));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertNotEmpty($filtered);
        self::assertLessThan(count($unfiltered), count($filtered));
        self::assertNotContains(PlayerFixture::PLAYER_REGULAR, $this->targetPlayerIds($filtered));
        self::assertContains(PlayerFixture::PLAYER_ADMIN, $this->targetPlayerIds($filtered));

        // The Inbox badge (GetInboxBadge, INB-02) leaves out what the list leaves out
        self::assertSame($this->unreadForYou($filtered), $this->badgeForYou([PlayerFixture::PLAYER_REGULAR]));
        self::assertEquals(
            min(array_map(static fn (PlayerNotification $notification): \DateTimeImmutable => $notification->notifiedAt, $filtered)),
            $this->query->getOldestUnreadNotifiedAtForPlayer(PlayerFixture::PLAYER_WITH_FAVORITES),
        );
    }

    public function testNotificationAboutAGroupTimeWithAHiddenMemberIsLeftOut(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR (not hidden) together with PLAYER_PRIVATE (hidden). A GroupSolvingTimeEdited
        // row, which the Inbox badge counts (favorites' times no longer do, INB-02)
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_PRIVATE);

        $before = count($this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100));

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);

        $filtered = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100);
        self::assertLessThan($before, count($filtered));
        self::assertSame($this->unreadForYou($filtered), $this->badgeForYou([PlayerFixture::PLAYER_PRIVATE]));

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
            $this->unreadForYou($this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 100)),
            $this->badgeForYou([PlayerFixture::PLAYER_REGULAR]),
        );
    }

    /**
     * The unread rows of a list the Inbox badge's For you part counts: every type but favorites' times and message
     * requests (GetInboxBadge::NOT_FOR_YOU). The read-row and index intents of the old bell count are in
     * GetInboxBadgeTest.
     *
     * @param array<PlayerNotification> $notifications
     */
    private function unreadForYou(array $notifications): int
    {
        return count(array_filter(
            $notifications,
            static fn (PlayerNotification $notification): bool => $notification->readAt === null
                && !in_array($notification->notificationType, GetInboxBadge::NOT_FOR_YOU, true),
        ));
    }

    /**
     * @param list<string> $hiddenIds what the viewer's profile row carries (UserBlockFixture + the test's blocks)
     */
    private function badgeForYou(array $hiddenIds): int
    {
        return self::getContainer()->get(GetInboxBadge::class)->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, $hiddenIds)->forYou;
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

    private function notify(string $playerId, string $type, string $targetColumn, string $targetId, bool $read = false, null|string $actorId = null): void
    {
        $readAt = $read ? 'NOW()' : 'NULL';

        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, read_at, {$targetColumn}, actor_player_id) VALUES (:id, :player, :type, NOW(), {$readAt}, :target, :actor)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'target' => $targetId, 'actor' => $actorId],
        );
    }

    /**
     * PLAYER_WITH_FAVORITES is told that the admin edited a shared time, newest of all their notifications.
     */
    private function groupEditOf(string $timeId): PlayerNotification
    {
        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id, actor_player_id) VALUES (:id, :player, 'GroupSolvingTimeEdited', NOW() + INTERVAL '1 day', :time, :actor)",
            ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'time' => $timeId, 'actor' => PlayerFixture::PLAYER_ADMIN],
        );

        $notifications = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 1);
        self::assertCount(1, $notifications);
        self::assertSame(NotificationType::GroupSolvingTimeEdited, $notifications[0]->notificationType);

        return $notifications[0];
    }

    private function unreadOfType(string $playerId, NotificationType $type): int
    {
        /** @var int|string $count */
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM notification WHERE player_id = :player AND type = :type AND read_at IS NULL',
            ['player' => $playerId, 'type' => $type->value],
        );

        return (int) $count;
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
