<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\ConversationRequestAlreadyPending;
use SpeedPuzzling\Web\Exceptions\DirectMessagesDisabled;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Exceptions\UserIsBlocked;
use SpeedPuzzling\Web\Message\StartConversation;
use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class StartConversationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetConversations $getConversations;
    private GetMessages $getMessages;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getConversations = $container->get(GetConversations::class);
        $this->getMessages = $container->get(GetMessages::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testStartingConversationCreatesPendingConversation(): void
    {
        // PLAYER_WITH_FAVORITES → PLAYER_ADMIN: no existing conversation between them in general context
        // But CONVERSATION_IGNORED exists between them - this shouldn't block new ones
        // Actually, let's use a different pair: ADMIN → WITH_STRIPE (no existing conversations in this direction)
        // Wait - CONVERSATION_MARKETPLACE exists between WITH_FAVORITES → WITH_STRIPE, but that's marketplace
        // Use: PLAYER_ADMIN → PLAYER_WITH_FAVORITES (no existing general conversation)
        $this->messageBus->dispatch(
            new StartConversation(
                initiatorId: PlayerFixture::PLAYER_ADMIN,
                recipientId: PlayerFixture::PLAYER_WITH_FAVORITES,
                initialMessage: 'Hello, nice puzzling profile!',
            ),
        );

        $conversations = $this->getConversations->forPlayer(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            ConversationStatus::Pending,
        );

        $found = false;
        foreach ($conversations as $conversation) {
            if ($conversation->otherPlayerId === PlayerFixture::PLAYER_ADMIN) {
                $found = true;
                break;
            }
        }

        self::assertTrue($found, 'Pending conversation should exist for recipient');
    }

    public function testStartingConversationWhenBlockedThrowsException(): void
    {
        // PLAYER_REGULAR blocks PLAYER_PRIVATE (from UserBlockFixture)
        // So PLAYER_PRIVATE trying to start conversation with PLAYER_REGULAR should fail
        try {
            $this->messageBus->dispatch(
                new StartConversation(
                    initiatorId: PlayerFixture::PLAYER_PRIVATE,
                    recipientId: PlayerFixture::PLAYER_REGULAR,
                    initialMessage: 'Hi there!',
                ),
            );
            self::fail('Expected UserIsBlocked exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(UserIsBlocked::class, $previous);
        }
    }

    public function testStartingConversationWhenDirectMessagesDisabledThrowsException(): void
    {
        // Disable direct messages for a player
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_WITH_FAVORITES);
        $player->changeAllowDirectMessages(false);

        try {
            $this->messageBus->dispatch(
                new StartConversation(
                    initiatorId: PlayerFixture::PLAYER_PRIVATE,
                    recipientId: PlayerFixture::PLAYER_WITH_FAVORITES,
                    initialMessage: 'Hi there!',
                ),
            );
            self::fail('Expected DirectMessagesDisabled exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(DirectMessagesDisabled::class, $previous);
        }
    }

    public function testDuplicatePendingRequestThrowsException(): void
    {
        // CONVERSATION_PENDING: WITH_STRIPE → REGULAR already exists as pending
        try {
            $this->messageBus->dispatch(
                new StartConversation(
                    initiatorId: PlayerFixture::PLAYER_WITH_STRIPE,
                    recipientId: PlayerFixture::PLAYER_REGULAR,
                    initialMessage: 'Another message',
                ),
            );
            self::fail('Expected ConversationRequestAlreadyPending exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(ConversationRequestAlreadyPending::class, $previous);
        }
    }

    public function testStartingConversationWithExistingAcceptedReusesConversation(): void
    {
        // CONVERSATION_ACCEPTED exists between REGULAR (initiator) and ADMIN (recipient), status = accepted
        // Starting general conversation from ADMIN → REGULAR should reuse and just send a message
        $this->messageBus->dispatch(
            new StartConversation(
                initiatorId: PlayerFixture::PLAYER_ADMIN,
                recipientId: PlayerFixture::PLAYER_REGULAR,
                initialMessage: 'This should be added to existing conversation',
            ),
        );

        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_ADMIN,
        );

        $lastMessage = end($messages);
        self::assertNotFalse($lastMessage);
        self::assertSame('This should be added to existing conversation', $lastMessage->content);
    }

    public function testStartingMarketplaceConversationAutoAcceptsWhenExistingAccepted(): void
    {
        // CONVERSATION_MARKETPLACE is accepted between WITH_FAVORITES → WITH_STRIPE
        // Starting another marketplace conversation should auto-accept
        $this->messageBus->dispatch(
            new StartConversation(
                initiatorId: PlayerFixture::PLAYER_WITH_FAVORITES,
                recipientId: PlayerFixture::PLAYER_WITH_STRIPE,
                initialMessage: 'Interested in this other puzzle too!',
                sellSwapListItemId: SellSwapListItemFixture::SELLSWAP_02,
            ),
        );

        // Should have a new accepted conversation for the new listing
        $conversations = $this->getConversations->forPlayer(PlayerFixture::PLAYER_WITH_FAVORITES);

        $found = false;
        foreach ($conversations as $conversation) {
            if ($conversation->sellSwapListItemId === SellSwapListItemFixture::SELLSWAP_02) {
                $found = true;
                self::assertSame(ConversationStatus::Accepted, $conversation->status);
                break;
            }
        }

        self::assertTrue($found, 'New marketplace conversation should be auto-accepted');
    }

    public function testMutedUserCannotStartConversation(): void
    {
        // Mute the player first
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_ADMIN);
        $player->muteMessaging(new DateTimeImmutable('+7 days'));

        try {
            $this->messageBus->dispatch(
                new StartConversation(
                    initiatorId: PlayerFixture::PLAYER_ADMIN,
                    recipientId: PlayerFixture::PLAYER_WITH_STRIPE,
                    initialMessage: 'Should not work - user is muted',
                ),
            );
            self::fail('Expected MessagingMuted exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MessagingMuted::class, $previous);
        }
    }
}
