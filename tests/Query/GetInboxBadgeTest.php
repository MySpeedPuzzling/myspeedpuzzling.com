<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Query\GetInboxBadge;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Results\ConversationOverview;
use SpeedPuzzling\Web\Results\PlayerNotification;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\LentPuzzleTransferFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SoldSwappedItemFixture;
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

    /**
     * Every notification type the For you list can show, unread, for PLAYER_WITH_FAVORITES - and the rows a list used to
     * drop while the badge counted them (INB-02 review F1): (a) an approved merge whose reported puzzle was merged away,
     * (b) a pair/team renamed by an actor the player blocked afterwards, (c) guest-link requested/accepted by a blocked
     * actor, (d) a transaction to rate whose seller deleted their account. The badge (explicit ids, as the profile row
     * carries them) and the list (forPlayer() of the signed-in viewer) agree with nobody hidden and with the actors
     * blocked; and every row is listed - none is left out of both.
     */
    public function testForYouCountsExactlyTheUnreadRowsTheListShowsForEveryType(): void
    {
        $viewer = PlayerFixture::PLAYER_WITH_FAVORITES;
        $survivorId = PuzzleFixture::PUZZLE_500_02;

        // (a) the reported puzzle was merged away: the request keeps its name and the survivor
        $mergedAwayId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO puzzle_merge_request (id, status, source_puzzle_id, source_puzzle_name, survivor_puzzle_id, reporter_id, submitted_at, reviewed_at, reported_duplicate_puzzle_ids, merged_puzzle_ids) VALUES (:id, 'approved', NULL, 'Reported And Merged Away', :survivor, :reporter, NOW(), NOW(), '[]', '[]')",
            ['id' => $mergedAwayId, 'survivor' => $survivorId, 'reporter' => $viewer],
        );
        $rejectedMergeId = Uuid::uuid7()->toString();
        $this->database->executeStatement(
            "INSERT INTO puzzle_merge_request (id, status, source_puzzle_id, source_puzzle_name, reporter_id, submitted_at, reviewed_at, rejection_reason, reported_duplicate_puzzle_ids, merged_puzzle_ids) VALUES (:id, 'rejected', :source, 'Kept', :reporter, NOW(), NOW(), 'Not a duplicate', '[]', '[]')",
            ['id' => $rejectedMergeId, 'source' => PuzzleFixture::PUZZLE_500_03, 'reporter' => $viewer],
        );

        // (d) SOLD_01's seller (PLAYER_ADMIN) deleted the account: anonymised as DeletePlayerHandler does
        $this->database->executeStatement(
            "UPDATE sold_swapped_item SET seller_id = NULL, seller_name = 'Former Seller' WHERE id = :id",
            ['id' => SoldSwappedItemFixture::SOLD_01],
        );

        // (c) PLAYER_WITH_STRIPE asked whether a guest of theirs is the viewer, and agreed to another one
        $guestLinkIds = [];
        foreach (['Guest Asked', 'Guest Agreed'] as $guestName) {
            $guestLinkIds[] = $guestLinkId = Uuid::uuid7()->toString();
            $this->database->executeStatement(
                'INSERT INTO guest_link_request (id, guest_key, guest_name, requested_at, requester_id, target_id) VALUES (:id, :key, :name, NOW(), :requester, :target)',
                ['id' => $guestLinkId, 'key' => strtolower($guestName), 'name' => $guestName, 'requester' => PlayerFixture::PLAYER_WITH_STRIPE, 'target' => $viewer],
            );
        }

        // (b) the pair of TIME_12 renamed by PLAYER_REGULAR
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($teamId);

        $this->notify($viewer, 'GroupSolvingTimeEdited', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_03, actorId: PlayerFixture::PLAYER_ADMIN);
        $this->notify($viewer, 'PuzzlePassedToYou', 'target_transfer_id', LentPuzzleTransferFixture::TRANSFER_06);
        $this->notify($viewer, 'PuzzleChangeRequestApproved', 'target_change_request_id', PuzzleReportFixture::CHANGE_REQUEST_APPROVED);
        $this->notify($viewer, 'PuzzleChangeRequestRejected', 'target_change_request_id', PuzzleReportFixture::CHANGE_REQUEST_REJECTED);
        $this->notify($viewer, 'PuzzleMergeRequestApproved', 'target_merge_request_id', $mergedAwayId);
        $this->notify($viewer, 'PuzzleMergeRequestRejected', 'target_merge_request_id', $rejectedMergeId);
        $this->notify($viewer, 'RateYourTransaction', 'target_sold_swapped_item_id', SoldSwappedItemFixture::SOLD_01);
        $this->notify($viewer, 'RateYourTransaction', 'target_sold_swapped_item_id', SoldSwappedItemFixture::SOLD_02);
        $this->notify($viewer, 'ModeratorRoleGranted', null, null);
        $this->notify($viewer, 'OfficialResultPublished', 'target_competition_round_id', CompetitionRoundFixture::ROUND_WJPC_FINAL);
        $this->notify($viewer, 'PuzzlingTeamRenamed', 'target_puzzling_team_id', $teamId, actorId: PlayerFixture::PLAYER_REGULAR);
        $this->notify($viewer, 'GuestLinkRequested', 'target_guest_link_request_id', $guestLinkIds[0], actorId: PlayerFixture::PLAYER_WITH_STRIPE);
        $this->notify($viewer, 'GuestLinkAccepted', 'target_guest_link_request_id', $guestLinkIds[1], actorId: PlayerFixture::PLAYER_WITH_STRIPE);
        // Listed, counted under Messages
        $this->notify($viewer, 'NewConversationRequest', 'target_conversation_id', ConversationFixture::CONVERSATION_PENDING);
        // Neither listed nor counted (INB-03)
        $this->notify($viewer, 'SubscribedPlayerAddedTime', 'target_solving_time_id', PuzzleSolvingTimeFixture::TIME_12);

        /** @var GetNotifications $notifications */
        $notifications = self::getContainer()->get(GetNotifications::class);
        $hiddenActors = [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE];

        // [hidden, For you: the fixture's group edit (actor PLAYER_REGULAR) + the 13 rows above]
        foreach ([[[], 14], [$hiddenActors, 10]] as [$hidden, $expected]) {
            if ($hidden === []) {
                TestingViewer::signOut(self::getContainer());
            } else {
                // The blocks came after the rows were created (an admin block after a report included)
                foreach ($hidden as $blockedId) {
                    $this->block($viewer, $blockedId);
                }

                TestingViewer::signIn(self::getContainer(), $viewer);
            }

            $unreadForYou = array_values(array_filter(
                $notifications->forPlayer($viewer, 200),
                static fn (PlayerNotification $notification): bool => $notification->readAt === null
                    && !in_array($notification->notificationType, GetInboxBadge::NOT_FOR_YOU, true),
            ));

            self::assertCount($expected, $unreadForYou, 'Every unread For you row is listed');
            self::assertSame(count($unreadForYou), $this->query->forPlayer($viewer, $hidden)->forYou, 'The badge counts exactly the listed rows');
        }

        // (a) listed by the name the request kept, linking the survivor; (d) the seller by the name the sale kept
        TestingViewer::signOut(self::getContainer());
        $rows = $notifications->forPlayer($viewer, 200);
        $mergedAway = array_values(array_filter($rows, static fn (PlayerNotification $row): bool => $row->mergeRequestId === $mergedAwayId));
        self::assertCount(1, $mergedAway);
        self::assertSame('Reported And Merged Away', $mergedAway[0]->mergeRequestPuzzleName);
        self::assertSame($survivorId, $mergedAway[0]->mergeRequestPuzzleId);
        $rating = array_values(array_filter($rows, static fn (PlayerNotification $row): bool => $row->soldSwappedItemId === SoldSwappedItemFixture::SOLD_01));
        self::assertCount(1, $rating);
        self::assertSame('Former Seller', $rating[0]->ratingOtherPlayerName);
        self::assertNull($rating[0]->ratingOtherPlayerId);
    }

    /**
     * A message request from an account deleted since (DeletePlayerHandler::anonymizeConversations(): initiator NULL,
     * still pending) is listed nowhere, so it is not counted - it could never be accepted or ignored (review F2).
     */
    public function testARequestFromADeletedAccountIsNotCounted(): void
    {
        self::assertSame(1, $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, [])->pendingRequests);

        $this->database->executeStatement(
            "UPDATE conversation SET initiator_id = NULL, initiator_name = 'Deleted Account' WHERE id = :id",
            ['id' => ConversationFixture::CONVERSATION_PENDING],
        );

        $parts = $this->query->forPlayer(PlayerFixture::PLAYER_REGULAR, []);
        self::assertSame(0, $parts->pendingRequests);
        self::assertSame([], self::getContainer()->get(GetConversations::class)->pendingRequestsForPlayer(PlayerFixture::PLAYER_REGULAR));
        self::assertSame(['route' => 'conversations_list', 'params' => []], $parts->target(), 'The Inbox link no longer opens an empty Requests tab');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideDeletedPartners(): iterable
    {
        // CONVERSATION_ACCEPTED: PLAYER_REGULAR (initiator) with PLAYER_ADMIN (recipient) - [the viewer, the deleted side]
        yield 'the recipient deleted' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN];
        yield 'the initiator deleted' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR];
    }

    /**
     * An accepted conversation whose other side deleted the account is in no list (GetConversations::forPlayer() joins
     * both players), so its unread messages are not counted (review F2).
     */
    #[DataProvider('provideDeletedPartners')]
    public function testAConversationWithADeletedPartnerIsNotCounted(string $viewerId, string $deletedId): void
    {
        // An unread message from the side that is deleted next
        $this->database->executeStatement(
            "INSERT INTO chat_message (id, conversation_id, sender_id, content, sent_at) VALUES (:id, :conversation, :sender, 'Hello', NOW())",
            ['id' => Uuid::uuid7()->toString(), 'conversation' => ConversationFixture::CONVERSATION_ACCEPTED, 'sender' => $deletedId],
        );
        $before = $this->query->forPlayer($viewerId, [])->unreadConversations;
        self::assertGreaterThanOrEqual(1, $before);

        // Anonymised as DeletePlayerHandler::anonymizeConversations() does
        $this->database->executeStatement(
            "UPDATE conversation SET initiator_id = CASE WHEN initiator_id = :deleted THEN NULL ELSE initiator_id END, recipient_id = CASE WHEN recipient_id = :deleted THEN NULL ELSE recipient_id END WHERE id = :id",
            ['id' => ConversationFixture::CONVERSATION_ACCEPTED, 'deleted' => $deletedId],
        );

        self::assertSame($before - 1, $this->query->forPlayer($viewerId, [])->unreadConversations);
        $listed = array_filter(
            self::getContainer()->get(GetConversations::class)->forPlayer($viewerId),
            static fn (ConversationOverview $conversation): bool => $conversation->conversationId === ConversationFixture::CONVERSATION_ACCEPTED,
        );
        self::assertSame([], $listed);
    }

    private function notify(string $playerId, string $type, null|string $targetColumn, null|string $targetId, bool $read = false, null|string $actorId = null): void
    {
        self::assertNotNull(NotificationType::tryFrom($type));
        $readAt = $read ? 'NOW()' : 'NULL';
        $columns = $targetColumn === null ? '' : ", {$targetColumn}";
        $values = $targetColumn === null ? '' : ', :target';
        $params = ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'type' => $type, 'actor' => $actorId];

        if ($targetColumn !== null) {
            $params['target'] = $targetId;
        }

        $this->database->executeStatement(
            "INSERT INTO notification (id, player_id, type, notified_at, read_at, actor_player_id{$columns}) VALUES (:id, :player, :type, NOW(), {$readAt}, :actor{$values})",
            $params,
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
