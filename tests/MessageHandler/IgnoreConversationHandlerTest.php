<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\ConversationNotFound;
use SpeedPuzzling\Web\Message\IgnoreConversation;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class IgnoreConversationHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private ConversationRepository $conversationRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->conversationRepository = $container->get(ConversationRepository::class);
    }

    public function testIgnoringConversationChangesStatusToIgnored(): void
    {
        // CONVERSATION_PENDING: WITH_STRIPE (initiator) → REGULAR (recipient)
        $this->messageBus->dispatch(
            new IgnoreConversation(
                conversationId: ConversationFixture::CONVERSATION_PENDING,
                playerId: PlayerFixture::PLAYER_REGULAR,
            ),
        );

        $conversation = $this->conversationRepository->get(ConversationFixture::CONVERSATION_PENDING);
        self::assertSame(ConversationStatus::Ignored, $conversation->status);
        self::assertNotNull($conversation->respondedAt);
    }

    public function testOnlyRecipientCanIgnore(): void
    {
        try {
            // WITH_STRIPE is the initiator, not the recipient
            $this->messageBus->dispatch(
                new IgnoreConversation(
                    conversationId: ConversationFixture::CONVERSATION_PENDING,
                    playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                ),
            );
            self::fail('Expected ConversationNotFound exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(ConversationNotFound::class, $previous);
        }
    }
}
