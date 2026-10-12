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
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The Inbox badge in one statement (docs/features/navigation INB-02, README §4.4 / D3). Fixtures
 * (.claude/fixtures.md): PLAYER_WITH_FAVORITES holds unread SubscribedPlayerAddedTime rows and one unread
 * GroupSolvingTimeEdited row on TIME_41 (actor PLAYER_REGULAR, the pair PLAYER_REGULAR + PLAYER_PRIVATE); PLAYER_REGULAR
 * has an unread accepted conversation with PLAYER_ADMIN and a pending request from PLAYER_WITH_STRIPE, and blocks
 * PLAYER_PRIVATE (UserBlockFixture).
 *
 * The count intents of GetNotificationsTest (hidden players, read rows, the index) and GetConversationsTest (unread
 * conversations) moved here with the two counts they tested.
 */
final class GetInboxBadgeTest extends KernelTestCase
{
    private GetInboxBadge $query;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetInboxBadge::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testFavoritesTimesAreNotCountedButGroupEditsAre(): void
    {
        $parts = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, []);

        // The group edit; the SubscribedPlayerAddedTime rows (favorites' times, D4) stay out
        self::assertSame(1, $parts->forYou);

        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);
        self::assertSame(1, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou);

        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        self::assertSame(2, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou);
    }

    public function testAPendingRequestIsCountedOnceUnderMessages(): void
    {
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, []);

        // CONVERSATION_PENDING: PLAYER_WITH_STRIPE asks PLAYER_REGULAR; CONVERSATION_ACCEPTED has unread messages from
        // PLAYER_ADMIN (and a system message)
        self::assertSame(1, $before->pendingRequests);
        self::assertSame(1, $before->unreadConversations);
        self::assertSame(2, $before->messages());

        // The request's notification belongs to For you's list, but the badge counts the request only under Messages
        $this->notify(PlayerFixture::PLAYER_REGULAR, 'NewConversationRequest', 'target_conversation_id', ConversationFixture::CONVERSATION_PENDING);

        $after = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, []);
        self::assertEquals($before, $after);
        self::assertSame($before->forYou + 2, $after->total());
    }

    public function testNoConversationsNoMessages(): void
    {
        // PLAYER_PRIVATE has no conversations (was GetConversationsTest::testCountUnreadForPlayerWithNoUnreadReturnsZero)
        $parts = $this->query->forPlayer(PlayerFixture::PLAYER_PRIVATE, []);

        self::assertSame(0, $parts->unreadConversations);
        self::assertSame(0, $parts->pendingRequests);
    }

    public function testBlockedPlayersAreLeftOutOfEveryPartWithTheIdsPassedExplicitly(): void
    {
        // For you rows naming PLAYER_ADMIN and PLAYER_WITH_STRIPE (the actors)
        $this->notify(PlayerFixture::PLAYER_REGULAR, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_03, actorId: PlayerFixture::PLAYER_ADMIN);
        $this->notify(PlayerFixture::PLAYER_REGULAR, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_05, actorId: PlayerFixture::PLAYER_WITH_STRIPE);
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, []);

        // PLAYER_REGULAR blocks the partner of the accepted conversation and the initiator of the request
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN);
        $this->block(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE);

        // A signed-in viewer is never asked: a push runs in somebody else's request (PLAYER_ADMIN blocks nobody)
        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        $hidden = [PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE];
        $after = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, $hidden);

        self::assertSame($before->forYou - 2, $after->forYou);
        self::assertSame(0, $after->unreadConversations);
        self::assertSame(0, $after->pendingRequests);

        // For you hides exactly the ids it is given (the conversation parts read user_block for the player itself)
        self::assertSame($before->forYou, $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, [])->forYou);
    }

    public function testAGroupTimeWithAHiddenMemberIsLeftOutUnlessThePlayerTookPart(): void
    {
        // TIME_12: tracked by PLAYER_REGULAR together with PLAYER_PRIVATE
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12, actorId: PlayerFixture::PLAYER_REGULAR);
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou;

        // PLAYER_PRIVATE is in TIME_12 and TIME_41: both rows left out
        self::assertSame($before - 2, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [PlayerFixture::PLAYER_PRIVATE])->forYou);

        // PLAYER_REGULAR took part in TIME_12 himself: his own group's news stays even with PLAYER_PRIVATE hidden
        $this->notify(PlayerFixture::PLAYER_REGULAR, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12, actorId: PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertSame(
            $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, [])->forYou,
            $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, [PlayerFixture::PLAYER_PRIVATE])->forYou,
        );
    }

    public function testForYouAgreesWithTheListOfTheSameViewer(): void
    {
        // PLAYER_WITH_FAVORITES blocks PLAYER_REGULAR: the list (forPlayer() of the signed-in viewer) and the badge
        // (explicit ids = what the profile row carries) leave out the same rows
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_03, actorId: PlayerFixture::PLAYER_ADMIN);
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        $this->block(PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_REGULAR);

        /** @var GetNotifications $notifications */
        $notifications = self::getContainer()->get(GetNotifications::class);

        foreach ([[], [PlayerFixture::PLAYER_REGULAR]] as $hidden) {
            if ($hidden === []) {
                TestingViewer::signOut(self::getContainer());
            } else {
                TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_WITH_FAVORITES);
            }

            $unreadForYou = array_filter(
                $notifications->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, 200),
                static fn (PlayerNotification $notification): bool => $notification->readAt === null
                    && !in_array($notification->notificationType, GetInboxBadge::NOT_FOR_YOU, true),
            );

            self::assertSame(count($unreadForYou), $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, $hidden)->forYou);
        }

        // Blocking PLAYER_REGULAR left out the TIME_41 row (his edit of his pair time); the admin's row and the lending one
        // stay (lending history is bilateral)
        self::assertSame(2, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [PlayerFixture::PLAYER_REGULAR])->forYou);
    }

    public function testReadNotificationsAreLeftOut(): void
    {
        $before = $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou;

        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_03, actorId: PlayerFixture::PLAYER_ADMIN);
        $this->notify(PlayerFixture::PLAYER_WITH_FAVORITES, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_05, actorId: PlayerFixture::PLAYER_WITH_STRIPE, read: true);

        self::assertSame($before + 1, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou);

        $this->database->executeStatement('UPDATE notification SET read_at = NOW() WHERE player_id = :player', ['player' => PlayerFixture::PLAYER_WITH_FAVORITES]);

        self::assertSame(0, $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, [])->forYou);
    }

    public function testOneStatementAndForYouCanUseItsPartialIndex(): void
    {
        /** @var DebugDataHolder $debugDataHolder */
        $debugDataHolder = self::getContainer()->get('doctrine.debug_data_holder');
        $debugDataHolder->reset();

        $this->query->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES, []);

        /** @var list<array{sql: string, params: array<mixed>}> $executed */
        $executed = $debugDataHolder->getData()['default'] ?? [];
        self::assertCount(1, $executed, 'The two counts the header ran before are one statement');

        // The test database is tiny, so the planner would scan it anyway - only prove the index is usable. The types are
        // literals in the SQL (never bound), or the predicate of the partial index could not match
        self::assertStringContainsString("NOT IN ('SubscribedPlayerAddedTime', 'NewConversationRequest')", $executed[0]['sql']);
        $this->database->executeStatement('SET LOCAL enable_seqscan = off');
        /** @var list<string> $plan */
        $plan = $this->database->fetchFirstColumn('EXPLAIN ' . $executed[0]['sql'], array_values($executed[0]['params']));

        self::assertMatchesRegularExpression('/Index Only Scan using custom_notification_unread_for_you on notification/', implode("\n", $plan));
    }

    private function notify(string $playerId, string $type, string $targetColumn, string $targetId, bool $read = false, null|string $actorId = null): void
    {
        self::assertNotNull(NotificationType::tryFrom($type));
        $readAt = $read ? 'NOW()' : 'NULL';

        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, read_at, actor_player_id, {$targetColumn}) VALUES (:id, :player, :type, NOW(), {$readAt}, :actor, :target)",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'actor' => $actorId, 'target' => $targetId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        $this->database->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
