<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ChatMessage;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Value\SystemMessageType;

final class ChatMessageFixture extends Fixture implements DependentFixtureInterface
{
    public const string MESSAGE_01 = '018d000f-0000-0000-0000-000000000001';
    public const string MESSAGE_02 = '018d000f-0000-0000-0000-000000000002';
    public const string MESSAGE_03 = '018d000f-0000-0000-0000-000000000003';
    public const string MESSAGE_04 = '018d000f-0000-0000-0000-000000000004';
    public const string MESSAGE_PENDING = '018d000f-0000-0000-0000-000000000005';
    public const string MESSAGE_MARKETPLACE_01 = '018d000f-0000-0000-0000-000000000006';
    public const string MESSAGE_MARKETPLACE_02 = '018d000f-0000-0000-0000-000000000007';
    public const string MESSAGE_OLD_UNREAD = '018d000f-0000-0000-0000-000000000008';
    public const string MESSAGE_SYSTEM_RESERVED = '018d000f-0000-0000-0000-000000000009';
    public const string MESSAGE_MARKETPLACE_COMPLETED_01 = '018d000f-0000-0000-0000-000000000010';
    public const string MESSAGE_SYSTEM_SOLD = '018d000f-0000-0000-0000-000000000011';

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $playerRegular = $this->getReference(PlayerFixture::PLAYER_REGULAR, Player::class);
        $playerAdmin = $this->getReference(PlayerFixture::PLAYER_ADMIN, Player::class);
        $playerWithStripe = $this->getReference(PlayerFixture::PLAYER_WITH_STRIPE, Player::class);
        $playerWithFavorites = $this->getReference(PlayerFixture::PLAYER_WITH_FAVORITES, Player::class);

        $acceptedConversation = $this->getReference(ConversationFixture::CONVERSATION_ACCEPTED, Conversation::class);
        $pendingConversation = $this->getReference(ConversationFixture::CONVERSATION_PENDING, Conversation::class);
        $marketplaceConversation = $this->getReference(ConversationFixture::CONVERSATION_MARKETPLACE, Conversation::class);

        $now = $this->clock->now();

        // Messages in accepted conversation (REGULAR ↔ ADMIN)
        $msg01 = $this->createMessage(
            id: self::MESSAGE_01,
            conversation: $acceptedConversation,
            sender: $playerRegular,
            content: 'Hi! I wanted to ask about puzzle solving techniques.',
            sentAt: $now->modify('-10 days'),
            readAt: $now->modify('-10 days'),
        );
        $manager->persist($msg01);

        $msg02 = $this->createMessage(
            id: self::MESSAGE_02,
            conversation: $acceptedConversation,
            sender: $playerAdmin,
            content: 'Sure, I would be happy to help! What specific area are you interested in?',
            sentAt: $now->modify('-9 days'),
            readAt: $now->modify('-9 days'),
        );
        $manager->persist($msg02);

        $msg03 = $this->createMessage(
            id: self::MESSAGE_03,
            conversation: $acceptedConversation,
            sender: $playerRegular,
            content: 'Mainly edge-first strategies for 1000 piece puzzles.',
            sentAt: $now->modify('-8 days'),
            readAt: $now->modify('-8 days'),
        );
        $manager->persist($msg03);

        // Last message from ADMIN - unread by REGULAR
        $msg04 = $this->createMessage(
            id: self::MESSAGE_04,
            conversation: $acceptedConversation,
            sender: $playerAdmin,
            content: 'I recommend sorting all edge pieces first, then grouping by color patterns.',
            sentAt: $now->modify('-1 day'),
        );
        $manager->persist($msg04);

        // Initial message in pending conversation (WITH_STRIPE → REGULAR)
        $msgPending = $this->createMessage(
            id: self::MESSAGE_PENDING,
            conversation: $pendingConversation,
            sender: $playerWithStripe,
            content: 'Hey, would you like to do a puzzle together sometime?',
            sentAt: $now->modify('-2 days'),
        );
        $manager->persist($msgPending);

        // Messages in marketplace conversation (WITH_FAVORITES ↔ WITH_STRIPE)
        $msgMarketplace01 = $this->createMessage(
            id: self::MESSAGE_MARKETPLACE_01,
            conversation: $marketplaceConversation,
            sender: $playerWithFavorites,
            content: 'Hi, is this puzzle still available?',
            sentAt: $now->modify('-5 days'),
            readAt: $now->modify('-5 days'),
        );
        $manager->persist($msgMarketplace01);

        $msgMarketplace02 = $this->createMessage(
            id: self::MESSAGE_MARKETPLACE_02,
            conversation: $marketplaceConversation,
            sender: $playerWithStripe,
            content: 'Yes, it is! Are you interested in buying or swapping?',
            sentAt: $now->modify('-3 days'),
        );
        $manager->persist($msgMarketplace02);

        // System message in accepted conversation - unread, to test mark-as-read with NULL sender_id
        $msgSystemReserved = new ChatMessage(
            id: Uuid::fromString(self::MESSAGE_SYSTEM_RESERVED),
            conversation: $acceptedConversation,
            sender: null,
            content: '',
            sentAt: $now->modify('-12 hours'),
            systemMessageType: SystemMessageType::ListingReserved,
        );
        $manager->persist($msgSystemReserved);

        // Messages in completed marketplace conversation (WITH_FAVORITES ↔ WITH_STRIPE)
        $completedMarketplaceConversation = $this->getReference(ConversationFixture::CONVERSATION_MARKETPLACE_COMPLETED, Conversation::class);

        $msgMktCompleted01 = $this->createMessage(
            id: self::MESSAGE_MARKETPLACE_COMPLETED_01,
            conversation: $completedMarketplaceConversation,
            sender: $playerWithFavorites,
            content: 'Hi, I would like to buy this puzzle.',
            sentAt: $now->modify('-7 days'),
            readAt: $now->modify('-7 days'),
        );
        $manager->persist($msgMktCompleted01);

        // System message: listing sold to WITH_FAVORITES (buyer)
        $msgSystemSold = new ChatMessage(
            id: Uuid::fromString(self::MESSAGE_SYSTEM_SOLD),
            conversation: $completedMarketplaceConversation,
            sender: null,
            content: '',
            sentAt: $now->modify('-3 days'),
            systemMessageType: SystemMessageType::ListingSold,
            systemMessageTargetPlayerId: $playerWithFavorites->id,
        );
        $manager->persist($msgSystemSold);

        // Old unread message from ADMIN to REGULAR (sent 2 days ago, unread) - for notification testing
        $msgOldUnread = $this->createMessage(
            id: self::MESSAGE_OLD_UNREAD,
            conversation: $acceptedConversation,
            sender: $playerAdmin,
            content: 'Also, have you tried sorting by image regions?',
            sentAt: $now->modify('-2 days'),
        );
        $manager->persist($msgOldUnread);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            ConversationFixture::class,
        ];
    }

    private function createMessage(
        string $id,
        Conversation $conversation,
        Player $sender,
        string $content,
        DateTimeImmutable $sentAt,
        null|DateTimeImmutable $readAt = null,
    ): ChatMessage {
        $message = new ChatMessage(
            id: Uuid::fromString($id),
            conversation: $conversation,
            sender: $sender,
            content: $content,
            sentAt: $sentAt,
            readAt: $readAt,
        );

        return $message;
    }
}
