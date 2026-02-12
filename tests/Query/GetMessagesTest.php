<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetMessagesTest extends KernelTestCase
{
    private GetMessages $getMessages;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getMessages = $container->get(GetMessages::class);
    }

    public function testReturnsMessagesForConversationInChronologicalOrder(): void
    {
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertCount(5, $messages);

        // Verify chronological order (ASC)
        for ($i = 0; $i < count($messages) - 1; $i++) {
            self::assertLessThanOrEqual($messages[$i + 1]->sentAt, $messages[$i]->sentAt);
        }
    }

    public function testIsOwnMessageFlag(): void
    {
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        // Messages 1, 3 are from REGULAR (own), messages 2, 4, 5 from ADMIN (not own)
        self::assertTrue($messages[0]->isOwnMessage);
        self::assertFalse($messages[1]->isOwnMessage);
        self::assertTrue($messages[2]->isOwnMessage);
        self::assertFalse($messages[3]->isOwnMessage);
        self::assertFalse($messages[4]->isOwnMessage);
    }

    public function testPaginationWorks(): void
    {
        $firstTwo = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
            limit: 2,
        );

        self::assertCount(2, $firstTwo);

        $nextTwo = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
            limit: 2,
            offset: 2,
        );

        self::assertCount(2, $nextTwo);

        // Ensure they are different messages
        self::assertNotSame($firstTwo[0]->messageId, $nextTwo[0]->messageId);
    }

    public function testReadAtIsPopulated(): void
    {
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        // First 3 messages have readAt set in fixtures
        self::assertNotNull($messages[0]->readAt);
        self::assertNotNull($messages[1]->readAt);
        self::assertNotNull($messages[2]->readAt);

        // Messages 4 and 5 (from ADMIN) have no readAt
        self::assertNull($messages[3]->readAt);
        self::assertNull($messages[4]->readAt);
    }
}
