<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Exceptions\MessagingMuted;
use SpeedPuzzling\Web\Message\SendMessage;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class SendMessageHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetMessages $getMessages;
    private ConversationRepository $conversationRepository;
    private PlayerRepository $playerRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getMessages = $container->get(GetMessages::class);
        $this->conversationRepository = $container->get(ConversationRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
    }

    public function testSendingMessageInAcceptedConversation(): void
    {
        $this->messageBus->dispatch(
            new SendMessage(
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                senderId: PlayerFixture::PLAYER_REGULAR,
                content: 'New test message',
            ),
        );

        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        $lastMessage = end($messages);
        self::assertNotFalse($lastMessage);
        self::assertSame('New test message', $lastMessage->content);
        self::assertTrue($lastMessage->isOwnMessage);
    }

    public function testSendingMessageUpdatesLastMessageAt(): void
    {
        $conversationBefore = $this->conversationRepository->get(ConversationFixture::CONVERSATION_ACCEPTED);
        $lastMessageAtBefore = $conversationBefore->lastMessageAt;

        $this->messageBus->dispatch(
            new SendMessage(
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                senderId: PlayerFixture::PLAYER_ADMIN,
                content: 'Another message to test lastMessageAt',
            ),
        );

        $conversationAfter = $this->conversationRepository->get(ConversationFixture::CONVERSATION_ACCEPTED);
        self::assertGreaterThanOrEqual($lastMessageAtBefore, $conversationAfter->lastMessageAt);
    }

    public function testCannotSendMessageInPendingConversation(): void
    {
        try {
            $this->messageBus->dispatch(
                new SendMessage(
                    conversationId: ConversationFixture::CONVERSATION_PENDING,
                    senderId: PlayerFixture::PLAYER_WITH_STRIPE,
                    content: 'Should not work',
                ),
            );
            self::fail('Expected ConversationNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(ConversationNotFound::class, $previous);
        }
    }

    public function testNonParticipantCannotSendMessage(): void
    {
        try {
            // PLAYER_WITH_FAVORITES is not a participant of CONVERSATION_ACCEPTED
            $this->messageBus->dispatch(
                new SendMessage(
                    conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                    senderId: PlayerFixture::PLAYER_WITH_FAVORITES,
                    content: 'Should not work',
                ),
            );
            self::fail('Expected ConversationNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(ConversationNotFound::class, $previous);
        }
    }

    public function testMutedUserCannotSendMessage(): void
    {
        // Mute the player first
        $player = $this->playerRepository->get(PlayerFixture::PLAYER_REGULAR);
        $player->muteMessaging(new DateTimeImmutable('+7 days'));

        try {
            $this->messageBus->dispatch(
                new SendMessage(
                    conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                    senderId: PlayerFixture::PLAYER_REGULAR,
                    content: 'Should not work - user is muted',
                ),
            );
            self::fail('Expected MessagingMuted exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MessagingMuted::class, $previous);
        }
    }
}
