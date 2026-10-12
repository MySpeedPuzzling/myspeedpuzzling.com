<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Events\ChatMessageSent;
use SpeedPuzzling\Web\Message\AcceptConversation;
use SpeedPuzzling\Web\Message\BlockUser;
use SpeedPuzzling\Web\Message\IgnoreConversation;
use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Message\UnblockUser;
use SpeedPuzzling\Web\MessageHandler\NotifyOnChatMessageSent;
use SpeedPuzzling\Web\Navigation\InboxBadge;
use SpeedPuzzling\Web\Services\MercureNotifier;
use SpeedPuzzling\Web\Tests\DataFixtures\ChatMessageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestDouble\NullMercureHub;
use SpeedPuzzling\Web\Tests\TestingViewer;
use SpeedPuzzling\Web\Value\InboxBadgeParts;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The realtime Inbox badge (docs/features/navigation INB-02): MercureNotifier::notifyInboxBadgeChanged() renders one
 * `inbox_badge` Turbo Stream with the numbers only - for-you, conversations, requests - on the player's existing
 * topic, from handlers that run without a request (async consumers) or in another player's request. The numbers are
 * the recipient's, with the recipient's blocks.
 */
final class MercureNotifierInboxBadgeTest extends KernelTestCase
{
    private const string STREAM = '/^<turbo-stream action="inbox_badge" targets="\.inbox-badge" for-you="(\d+)" conversations="(\d+)" requests="(\d+)" at="(\d+)"><\/turbo-stream>$/';

    private NullMercureHub $hub;

    protected function setUp(): void
    {
        self::bootKernel();
        $hub = self::getContainer()->get(NullMercureHub::class); // @phpstan-ignore symfonyContainer.serviceNotFound
        assert($hub instanceof NullMercureHub);
        $hub->reset();
        $this->hub = $hub;
    }

    public function testAChatMessageSendsTheRecipientsWholeBadgeWithoutARequest(): void
    {
        self::assertNull(self::getContainer()->get(RequestStack::class)->getCurrentRequest());

        // MESSAGE_04: PLAYER_ADMIN → PLAYER_REGULAR in CONVERSATION_ACCEPTED; PLAYER_REGULAR also has a pending request
        $this->handleChatMessageSent(ChatMessageFixture::MESSAGE_04, ConversationFixture::CONVERSATION_ACCEPTED, PlayerFixture::PLAYER_ADMIN);

        $badge = $this->onlyBadgePushTo(PlayerFixture::PLAYER_REGULAR);
        $expected = self::getContainer()->get(InboxBadge::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([$expected->forYou, 1, 1], [$badge['forYou'], $badge['conversations'], $badge['requests']]);
        self::assertSame($expected->unreadConversations, $badge['conversations']);
        self::assertSame($expected->pendingRequests, $badge['requests']);
        self::assertTrue($badge['private']);
        self::assertSame([], $this->badgePushesTo(PlayerFixture::PLAYER_ADMIN), 'The sender\'s own badge did not change');
    }

    public function testTheFirstMessageOfARequestUpdatesTheRecipientsMessagesPart(): void
    {
        // MESSAGE_PENDING: PLAYER_WITH_STRIPE asks PLAYER_REGULAR (CONVERSATION_PENDING)
        $this->handleChatMessageSent(ChatMessageFixture::MESSAGE_PENDING, ConversationFixture::CONVERSATION_PENDING, PlayerFixture::PLAYER_WITH_STRIPE);

        $badge = $this->onlyBadgePushTo(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(1, $badge['requests']);
    }

    public function testThePushAppliesTheRecipientsBlocksNeverTheRequestViewers(): void
    {
        $database = self::getContainer()->get(Connection::class);

        // PLAYER_REGULAR blocks PLAYER_PRIVATE (UserBlockFixture): of three For you rows naming PLAYER_PRIVATE,
        // PLAYER_WITH_FAVORITES and PLAYER_WITH_STRIPE, PLAYER_REGULAR is shown two
        foreach ([PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE] as $actorId) {
            $database->executeStatement(
                "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id, actor_player_id) VALUES (:id, :player, 'GroupSolvingTimeEdited', NOW(), :time, :actor)",
                ['id' => Uuid::uuid7()->toString(), 'player' => PlayerFixture::PLAYER_REGULAR, 'time' => PuzzleSolvingTimeFixture::TIME_03, 'actor' => $actorId],
            );
        }

        // The handler runs in the request of the sender, who blocks the other two: with the sender's blocks the number
        // would be 1, with nobody's 3
        foreach ([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE] as $blockedId) {
            $database->executeStatement(
                "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
                ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_ADMIN, 'blocked' => $blockedId],
            );
        }

        TestingViewer::signIn(self::getContainer(), PlayerFixture::PLAYER_ADMIN);

        $this->handleChatMessageSent(ChatMessageFixture::MESSAGE_04, ConversationFixture::CONVERSATION_ACCEPTED, PlayerFixture::PLAYER_ADMIN);

        self::assertSame(2, $this->onlyBadgePushTo(PlayerFixture::PLAYER_REGULAR)['forYou']);
    }

    public function testReadingAConversationPushesTheReadersBadge(): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new MarkMessagesAsRead(
            conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
            playerId: PlayerFixture::PLAYER_REGULAR,
        ));

        $badge = $this->onlyBadgePushTo(PlayerFixture::PLAYER_REGULAR);
        self::assertSame(0, $badge['conversations']);
        self::assertSame(1, $badge['requests']);
    }

    /**
     * @return iterable<string, array{object, int, int, list<string>}>
     */
    public static function provideAnswersAndBlocks(): iterable
    {
        // [the message, the acting player's unread conversations and requests afterwards, who must get no push]
        // CONVERSATION_PENDING (PLAYER_WITH_STRIPE asks PLAYER_REGULAR) holds an unread first message; CONVERSATION_ACCEPTED
        // (with PLAYER_ADMIN) an unread one
        yield 'a request accepted' => [new AcceptConversation(ConversationFixture::CONVERSATION_PENDING, PlayerFixture::PLAYER_REGULAR), 2, 0, [PlayerFixture::PLAYER_WITH_STRIPE]];
        yield 'a request ignored' => [new IgnoreConversation(ConversationFixture::CONVERSATION_PENDING, PlayerFixture::PLAYER_REGULAR), 1, 0, [PlayerFixture::PLAYER_WITH_STRIPE]];
        yield 'the conversation partner blocked' => [new BlockUser(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_ADMIN), 0, 1, [PlayerFixture::PLAYER_ADMIN]];
        // UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE
        yield 'a block lifted' => [new UnblockUser(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE), 1, 1, [PlayerFixture::PLAYER_PRIVATE]];
    }

    /**
     * Answering a message request, blocking and unblocking change the acting player's badge (INB-02 review Q2): their
     * other open tabs get one push with the numbers read after the change committed (PushInboxBadge after the bus) -
     * never the other side, whom a block must not reach.
     *
     * @param list<string> $notPushedTo
     */
    #[DataProvider('provideAnswersAndBlocks')]
    public function testTheActingPlayersOtherTabsGetTheNewBadge(object $message, int $conversations, int $requests, array $notPushedTo): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch($message);

        $badge = $this->onlyBadgePushTo(PlayerFixture::PLAYER_REGULAR);
        $expected = self::getContainer()->get(InboxBadge::class)->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertSame([$conversations, $requests], [$badge['conversations'], $badge['requests']]);
        self::assertSame([$expected->forYou, $expected->unreadConversations, $expected->pendingRequests], [$badge['forYou'], $badge['conversations'], $badge['requests']]);

        foreach ($notPushedTo as $playerId) {
            self::assertSame([], $this->badgePushesTo($playerId));
        }
    }

    public function testTheStreamCarriesNumbersOnly(): void
    {
        self::getContainer()->get(MercureNotifier::class)->notifyInboxBadgeChanged(PlayerFixture::PLAYER_REGULAR, new InboxBadgeParts(120, 2, 1));

        $updates = $this->badgePushesTo(PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $updates);
        self::assertMatchesRegularExpression(self::STREAM, trim($updates[0]->getData()));
        self::assertStringContainsString('for-you="120" conversations="2" requests="1"', $updates[0]->getData());
    }

    private function handleChatMessageSent(string $messageId, string $conversationId, string $senderId): void
    {
        /** @var NotifyOnChatMessageSent $handler */
        $handler = self::getContainer()->get(NotifyOnChatMessageSent::class);
        $handler(new ChatMessageSent(Uuid::fromString($messageId), Uuid::fromString($conversationId), $senderId));
    }

    /**
     * @return array{forYou: int, conversations: int, requests: int, private: bool}
     */
    private function onlyBadgePushTo(string $playerId): array
    {
        $updates = $this->badgePushesTo($playerId);
        self::assertCount(1, $updates, 'One badge update for every instance on the player\'s pages');

        self::assertSame(1, preg_match(self::STREAM, trim($updates[0]->getData()), $matches), $updates[0]->getData());

        return [
            'forYou' => (int) $matches[1],
            'conversations' => (int) $matches[2],
            'requests' => (int) $matches[3],
            'private' => $updates[0]->isPrivate(),
        ];
    }

    /**
     * @return list<Update>
     */
    private function badgePushesTo(string $playerId): array
    {
        return array_values(array_filter(
            $this->hub->getPublishedUpdates(),
            static fn (Update $update): bool => in_array('/unread-count/' . $playerId, $update->getTopics(), true),
        ));
    }
}
