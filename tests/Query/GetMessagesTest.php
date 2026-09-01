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
        $page = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        );

        self::assertCount(6, $page->messages);
        self::assertFalse($page->hasOlderMessages);

        // Verify chronological order (ASC)
        for ($i = 0; $i < count($page->messages) - 1; $i++) {
            self::assertLessThanOrEqual($page->messages[$i + 1]->sentAt, $page->messages[$i]->sentAt);
        }
    }

    public function testIsOwnMessageFlag(): void
    {
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        )->messages;

        // Messages 1, 3 are from REGULAR (own), messages 2, 4, 5 from ADMIN (not own)
        self::assertTrue($messages[0]->isOwnMessage);
        self::assertFalse($messages[1]->isOwnMessage);
        self::assertTrue($messages[2]->isOwnMessage);
        self::assertFalse($messages[3]->isOwnMessage);
        self::assertFalse($messages[4]->isOwnMessage);
    }

    public function testLimitReturnsNewestMessages(): void
    {
        $allMessages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        )->messages;

        $page = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
            limit: 2,
        );

        self::assertCount(2, $page->messages);
        self::assertTrue($page->hasOlderMessages);

        // The window must contain the NEWEST messages, still in chronological order
        $lastTwo = array_slice($allMessages, -2);
        self::assertSame($lastTwo[0]->messageId, $page->messages[0]->messageId);
        self::assertSame($lastTwo[1]->messageId, $page->messages[1]->messageId);
    }

    public function testCursorPaginationWalksWholeHistoryBackwards(): void
    {
        $allMessages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        )->messages;

        $collected = [];
        $cursor = null;

        do {
            $page = $this->getMessages->forConversation(
                ConversationFixture::CONVERSATION_ACCEPTED,
                PlayerFixture::PLAYER_REGULAR,
                limit: 2,
                beforeMessageId: $cursor,
            );

            // Older batches are prepended before what we already have
            $collected = array_merge($page->messages, $collected);
            $cursor = $page->oldestMessageId();
        } while ($page->hasOlderMessages);

        self::assertCount(count($allMessages), $collected);
        self::assertSame(
            array_map(static fn ($message) => $message->messageId, $allMessages),
            array_map(static fn ($message) => $message->messageId, $collected),
        );
    }

    public function testCursorFromDifferentConversationReturnsNothing(): void
    {
        $marketplaceMessages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_MARKETPLACE,
            PlayerFixture::PLAYER_REGULAR,
        )->messages;

        self::assertNotEmpty($marketplaceMessages);

        $page = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
            beforeMessageId: $marketplaceMessages[0]->messageId,
        );

        self::assertSame([], $page->messages);
        self::assertFalse($page->hasOlderMessages);
    }

    public function testReadAtIsPopulated(): void
    {
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_ACCEPTED,
            PlayerFixture::PLAYER_REGULAR,
        )->messages;

        // First 3 messages have readAt set in fixtures
        self::assertNotNull($messages[0]->readAt);
        self::assertNotNull($messages[1]->readAt);
        self::assertNotNull($messages[2]->readAt);

        // Messages 4 and 5 (from ADMIN) have no readAt
        self::assertNull($messages[3]->readAt);
        self::assertNull($messages[4]->readAt);
    }
}
