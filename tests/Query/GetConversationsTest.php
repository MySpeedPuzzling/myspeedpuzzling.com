<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Tests\DataFixtures\ConversationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\ConversationStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetConversationsTest extends KernelTestCase
{
    private GetConversations $getConversations;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->getConversations = $container->get(GetConversations::class);
    }

    public function testForPlayerReturnsAcceptedConversationsSortedByLastMessageAt(): void
    {
        // PLAYER_REGULAR has CONVERSATION_ACCEPTED (accepted) and CONVERSATION_PENDING (pending, as recipient)
        $conversations = $this->getConversations->forPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($conversations);

        // Only accepted conversations should be returned by default
        foreach ($conversations as $conversation) {
            self::assertSame(ConversationStatus::Accepted, $conversation->status);
        }

        // Verify sorted by lastMessageAt DESC
        for ($i = 0; $i < count($conversations) - 1; $i++) {
            if ($conversations[$i]->lastMessageAt !== null && $conversations[$i + 1]->lastMessageAt !== null) {
                self::assertGreaterThanOrEqual(
                    $conversations[$i + 1]->lastMessageAt,
                    $conversations[$i]->lastMessageAt,
                );
            }
        }
    }

    public function testPendingRequestsForPlayerReturnsOnlyPendingWherePlayerIsRecipient(): void
    {
        // PLAYER_REGULAR is recipient of CONVERSATION_PENDING
        $pendingRequests = $this->getConversations->pendingRequestsForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertNotEmpty($pendingRequests);

        foreach ($pendingRequests as $request) {
            self::assertSame(ConversationStatus::Pending, $request->status);
        }

        // PLAYER_WITH_STRIPE is initiator of CONVERSATION_PENDING, not recipient
        $pendingForStripe = $this->getConversations->pendingRequestsForPlayer(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertEmpty($pendingForStripe);
    }

    public function testCountUnreadForPlayerReturnsCorrectCount(): void
    {
        // PLAYER_REGULAR has unread MESSAGE_04 from ADMIN in CONVERSATION_ACCEPTED
        $unreadCount = $this->getConversations->countUnreadForPlayer(PlayerFixture::PLAYER_REGULAR);

        self::assertGreaterThanOrEqual(1, $unreadCount);
    }

    public function testCountUnreadForPlayerWithNoUnreadReturnsZero(): void
    {
        // PLAYER_PRIVATE has no conversations
        $unreadCount = $this->getConversations->countUnreadForPlayer(PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(0, $unreadCount);
    }

    public function testForPlayerIncludesMarketplaceConversation(): void
    {
        // PLAYER_WITH_STRIPE has CONVERSATION_MARKETPLACE (accepted)
        $conversations = $this->getConversations->forPlayer(PlayerFixture::PLAYER_WITH_STRIPE);

        $marketplaceConversation = null;
        foreach ($conversations as $conversation) {
            if ($conversation->conversationId === ConversationFixture::CONVERSATION_MARKETPLACE) {
                $marketplaceConversation = $conversation;
                break;
            }
        }

        self::assertNotNull($marketplaceConversation);
        self::assertNotNull($marketplaceConversation->puzzleName);
    }
}
