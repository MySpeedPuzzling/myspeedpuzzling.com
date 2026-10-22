<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\DirectMessagesDisabled;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\PartnerReplyNotPossible;
use SpeedPuzzling\Web\Exceptions\PartnerRequestNotFound;
use SpeedPuzzling\Web\Message\ReplyToPartnerRequest;
use SpeedPuzzling\Web\Message\SendPartnerRequest;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Repository\PartnerRequestRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PartnerFinderFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\PartnerKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Reply = a chat from the listed puzzler's side (docs/features/partner-finder.md D6) - through the asker's "direct
 * messages off", and only for the listing's owner: StartConversation's bypass checks the request itself.
 */
final class ReplyToPartnerRequestHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testOwnerReplyOpensAChatEvenWithTheAskersDirectMessagesOff(): void
    {
        $requestId = Uuid::uuid7()->toString();
        $this->messageBus->dispatch(new SendPartnerRequest(
            requestId: $requestId,
            listingId: PartnerFinderFixture::LISTING_REGULAR_PAIR,
            competitionId: PartnerFinderFixture::COMPETITION_PARTNER_FINDER_OPEN,
            fromPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
            kind: PartnerKind::Pair,
            note: null,
        ));

        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_FAVORITES)->changeAllowDirectMessages(false);

        $this->messageBus->dispatch(new ReplyToPartnerRequest($requestId, PlayerFixture::PLAYER_REGULAR, 'en'));

        $conversation = $this->connection->fetchAssociative(
            'SELECT id, status FROM conversation WHERE initiator_id = :owner AND recipient_id = :asker',
            ['owner' => PlayerFixture::PLAYER_REGULAR, 'asker' => PlayerFixture::PLAYER_WITH_FAVORITES],
        );
        self::assertIsArray($conversation);
        self::assertSame('accepted', $conversation['status'], 'The asker asked first - no conversation request step');

        $content = $this->connection->fetchOne('SELECT content FROM chat_message WHERE conversation_id = :id', ['id' => $conversation['id']]);
        self::assertSame("Hi Michael Johnson, thanks for getting in touch about Partner Finder Open - let's talk!", $content);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertTrue(self::getContainer()->get(PartnerRequestRepository::class)->get($requestId)->isReplied());

        // Replying again leads back to the same chat - no second message
        $this->messageBus->dispatch(new ReplyToPartnerRequest($requestId, PlayerFixture::PLAYER_REGULAR, 'en'));
        self::assertSame(1, $this->connection->fetchOne('SELECT COUNT(*) FROM chat_message WHERE conversation_id = :id', ['id' => $conversation['id']]));
    }

    public function testAPendingConversationBetweenThemIsAcceptedInstead(): void
    {
        // CONVERSATION_PENDING: PLAYER_WITH_STRIPE → PLAYER_REGULAR, and her request on his listing
        $this->messageBus->dispatch(new ReplyToPartnerRequest(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR, PlayerFixture::PLAYER_REGULAR, 'en'));

        self::assertSame('accepted', $this->connection->fetchOne('SELECT status FROM conversation WHERE id = :id', ['id' => ConversationFixture::CONVERSATION_PENDING]));
        self::assertSame(
            "Hi Sarah Williams, thanks for getting in touch about Partner Finder Open - let's talk!",
            $this->connection->fetchOne(
                'SELECT content FROM chat_message WHERE conversation_id = :id AND sender_id = :sender ORDER BY sent_at DESC LIMIT 1',
                ['id' => ConversationFixture::CONVERSATION_PENDING, 'sender' => PlayerFixture::PLAYER_REGULAR],
            ),
        );
    }

    public function testAMutedReplierChangesNothing(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_REGULAR)->muteMessaging(new \DateTimeImmutable('+7 days'));

        try {
            $this->messageBus->dispatch(new ReplyToPartnerRequest(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR, PlayerFixture::PLAYER_REGULAR, 'en'));
            self::fail('MessagingMuted expected');
        } catch (HandlerFailedException $exception) {
            self::assertNotSame([], $exception->getWrappedExceptions(MessagingMuted::class, true));
        }

        $this->assertNothingChanged();
    }

    public function testAnAskerWhoBlockedTheOwnerSinceGetsNoChat(): void
    {
        $this->connection->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_STRIPE, 'blocked' => PlayerFixture::PLAYER_REGULAR],
        );

        try {
            $this->messageBus->dispatch(new ReplyToPartnerRequest(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR, PlayerFixture::PLAYER_REGULAR, 'en'));
            self::fail('PartnerReplyNotPossible expected');
        } catch (PartnerReplyNotPossible) {
        }

        $this->assertNothingChanged();
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM conversation WHERE initiator_id = :owner AND recipient_id = :asker',
            ['owner' => PlayerFixture::PLAYER_REGULAR, 'asker' => PlayerFixture::PLAYER_WITH_STRIPE],
        ));
    }

    public function testSomebodyElseCannotReply(): void
    {
        $this->expectException(PartnerRequestNotFound::class);

        $this->messageBus->dispatch(new ReplyToPartnerRequest(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR, PlayerFixture::PLAYER_ADMIN, 'en'));
    }

    public function testTheBypassHoldsOnlyForTheListingOwnerToTheAsker(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE)->changeAllowDirectMessages(false);

        // PLAYER_ADMIN naming somebody else's request to write to its asker
        $this->expectException(PartnerRequestNotFound::class);

        $this->messageBus->dispatch(new StartConversation(
            initiatorId: PlayerFixture::PLAYER_ADMIN,
            recipientId: PlayerFixture::PLAYER_WITH_STRIPE,
            initialMessage: 'Hi!',
            partnerRequestId: PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR,
        ));
    }

    public function testWithoutARequestDirectMessagesOffStillRefuses(): void
    {
        self::getContainer()->get(PlayerRepository::class)->get(PlayerFixture::PLAYER_WITH_STRIPE)->changeAllowDirectMessages(false);

        try {
            $this->messageBus->dispatch(new StartConversation(
                initiatorId: PlayerFixture::PLAYER_REGULAR,
                recipientId: PlayerFixture::PLAYER_WITH_STRIPE,
                initialMessage: 'Hi!',
            ));
            self::fail('DirectMessagesDisabled expected');
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(DirectMessagesDisabled::class, $exception->getPrevious());
        }
    }

    /**
     * The fixture request is not marked replied and her pending conversation request (CONVERSATION_PENDING) stays as it was
     */
    private function assertNothingChanged(): void
    {
        // In memory too: a rolled-back transaction does not clear the entity manager, so nothing may be marked first
        $requests = self::getContainer()->get(PartnerRequestRepository::class);
        self::assertFalse($requests->get(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR)->isReplied(), 'marked replied in memory');

        self::getContainer()->get(EntityManagerInterface::class)->clear();

        self::assertFalse(self::getContainer()->get(PartnerRequestRepository::class)->get(PartnerFinderFixture::REQUEST_STRIPE_TO_REGULAR)->isReplied());
        self::assertSame('pending', $this->connection->fetchOne('SELECT status FROM conversation WHERE id = :id', ['id' => ConversationFixture::CONVERSATION_PENDING]));
        self::assertSame(0, $this->connection->fetchOne(
            'SELECT COUNT(*) FROM chat_message WHERE conversation_id = :id AND sender_id = :owner',
            ['id' => ConversationFixture::CONVERSATION_PENDING, 'owner' => PlayerFixture::PLAYER_REGULAR],
        ));
    }
}
