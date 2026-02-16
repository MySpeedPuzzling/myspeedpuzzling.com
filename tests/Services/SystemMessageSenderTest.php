<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Query\GetMessages;
use SpeedPuzzling\Web\Repository\ConversationRepository;
use SpeedPuzzling\Web\Repository\SellSwapListItemRepository;
use SpeedPuzzling\Web\Services\SystemMessageSender;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\SellSwapListItemFixture;
use SpeedPuzzling\Web\Value\SystemMessageType;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class SystemMessageSenderTest extends KernelTestCase
{
    private SystemMessageSender $systemMessageSender;
    private SellSwapListItemRepository $sellSwapListItemRepository;
    private ConversationRepository $conversationRepository;
    private GetMessages $getMessages;
    private GetConversations $getConversations;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->systemMessageSender = $container->get(SystemMessageSender::class);
        $this->sellSwapListItemRepository = $container->get(SellSwapListItemRepository::class);
        $this->conversationRepository = $container->get(ConversationRepository::class);
        $this->getMessages = $container->get(GetMessages::class);
        $this->getConversations = $container->get(GetConversations::class);
    }

    public function testSystemMessageIsCreatedInConversation(): void
    {
        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
        );

        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_MARKETPLACE,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );

        $systemMessages = array_filter($messages, static fn ($m) => $m->isSystemMessage);
        self::assertCount(1, $systemMessages);

        $systemMessage = array_values($systemMessages)[0];
        self::assertSame('messaging.system.listing_reserved', $systemMessage->systemTranslationKey);
        self::assertNull($systemMessage->senderId);
    }

    public function testSystemMessageWithTargetPlayerResolvesDifferentlyPerViewer(): void
    {
        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
            $item->player->id, // Reserved for WITH_STRIPE (the seller/recipient)
        );

        // Viewed by WITH_STRIPE (the target) → "reserved for you"
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_MARKETPLACE,
            PlayerFixture::PLAYER_WITH_STRIPE,
        );
        $systemMessages = array_values(array_filter($messages, static fn ($m) => $m->isSystemMessage));
        self::assertSame('messaging.system.listing_reserved_for_you', $systemMessages[0]->systemTranslationKey);

        // Viewed by WITH_FAVORITES (the initiator, not the target) → "reserved for someone else"
        $messages = $this->getMessages->forConversation(
            ConversationFixture::CONVERSATION_MARKETPLACE,
            PlayerFixture::PLAYER_WITH_FAVORITES,
        );
        $systemMessages = array_values(array_filter($messages, static fn ($m) => $m->isSystemMessage));
        self::assertSame('messaging.system.listing_reserved_for_someone_else', $systemMessages[0]->systemTranslationKey);
    }

    public function testNoErrorWhenNoConversationsExist(): void
    {
        // SELLSWAP_02 has no conversations linked
        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_02);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
        );

        $this->expectNotToPerformAssertions();
    }

    public function testLastMessageAtIsUpdated(): void
    {
        $conversationBefore = $this->conversationRepository->get(ConversationFixture::CONVERSATION_MARKETPLACE);
        $lastMessageAtBefore = $conversationBefore->lastMessageAt;

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReservationRemoved,
        );

        $conversationAfter = $this->conversationRepository->get(ConversationFixture::CONVERSATION_MARKETPLACE);
        self::assertNotNull($conversationAfter->lastMessageAt);

        if ($lastMessageAtBefore !== null) {
            self::assertGreaterThanOrEqual($lastMessageAtBefore, $conversationAfter->lastMessageAt);
        }
    }

    public function testSystemMessagesCountAsUnread(): void
    {
        // PLAYER_WITH_STRIPE has no unread in CONVERSATION_MARKETPLACE (last message was from them)
        $unreadBefore = $this->getConversations->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        $item = $this->sellSwapListItemRepository->get(SellSwapListItemFixture::SELLSWAP_01);

        $this->systemMessageSender->sendToAllConversations(
            $item,
            SystemMessageType::ListingReserved,
        );

        $unreadAfter = $this->getConversations->countUnreadForPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertGreaterThan($unreadBefore, $unreadAfter);
    }
}
