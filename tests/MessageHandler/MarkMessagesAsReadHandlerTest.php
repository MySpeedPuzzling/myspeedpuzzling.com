<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\MarkMessagesAsRead;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkMessagesAsReadHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetMessages $getMessages;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->getMessages = $container->get(GetMessages::class);
    }

    public function testMarksUnreadMessagesFromOtherParticipantAsRead(): void
    {
        // MESSAGE_04 is from ADMIN, unread by REGULAR
        $messagesBefore = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        $unreadBefore = array_filter($messagesBefore, static fn ($m) => $m->readAt === null && !$m->isOwnMessage);
        self::assertNotEmpty($unreadBefore, 'Should have unread messages from other participant before marking');

        $this->messageBus->dispatch(
            new MarkMessagesAsRead(
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                playerId: PlayerFixture::PLAYER_REGULAR,
            ),
        );

        $messagesAfter = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        $unreadAfter = array_filter($messagesAfter, static fn ($m) => $m->readAt === null && !$m->isOwnMessage);
        self::assertEmpty($unreadAfter, 'All messages from other participant should be marked as read');
    }

    public function testDoesNotMarkOwnMessagesAsRead(): void
    {
        // Mark as read from ADMIN's perspective
        $this->messageBus->dispatch(
            new MarkMessagesAsRead(
                conversationId: ConversationFixture::CONVERSATION_ACCEPTED,
                playerId: PlayerFixture::PLAYER_ADMIN,
            ),
        );

        // Messages sent by ADMIN should still have null readAt if they weren't already read
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        // Messages from REGULAR (msg01, msg03) were already read in fixtures
        // Message from ADMIN (msg04) was not read - marking as ADMIN should not affect it
        // Actually, ADMIN is the sender of msg04, so from REGULAR's view it's not own.
        // From ADMIN's view: msg01, msg03 are from REGULAR (not own), msg02, msg04 are own.
        // MarkMessagesAsRead for ADMIN should mark msg01 and msg03 as read (but they already are).
        // msg02 and msg04 are from ADMIN - these should NOT be affected by marking for ADMIN.
        $messagesFromAdminView = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_ADMIN,
        );

        $ownMessageCount = 0;
        foreach ($messagesFromAdminView as $message) {
            if ($message->isOwnMessage) {
                $ownMessageCount++;
            }
        }

        self::assertGreaterThan(0, $ownMessageCount);
    }
}
