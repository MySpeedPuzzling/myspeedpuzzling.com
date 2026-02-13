<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\DataFixtures;

use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Conversation;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\SellSwapListItem;
use SpeedPuzzling\Web\Value\ConversationStatus;

final class ConversationFixture extends Fixture implements DependentFixtureInterface
{
    public const string CONVERSATION_ACCEPTED = '018d000e-0000-0000-0000-000000000001';
    public const string CONVERSATION_PENDING = '018d000e-0000-0000-0000-000000000002';
    public const string CONVERSATION_MARKETPLACE = '018d000e-0000-0000-0000-000000000003';
    public const string CONVERSATION_IGNORED = '018d000e-0000-0000-0000-000000000004';

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

        $sellSwapItem01 = $this->getReference(SellSwapListItemFixture::SELLSWAP_01, SellSwapListItem::class);
        $puzzle500_01 = $this->getReference(PuzzleFixture::PUZZLE_500_01, Puzzle::class);

        $now = $this->clock->now();

        // Accepted conversation: REGULAR (initiator) → ADMIN (recipient)
        $acceptedConversation = new Conversation(
            id: Uuid::fromString(self::CONVERSATION_ACCEPTED),
            initiator: $playerRegular,
            recipient: $playerAdmin,
            status: ConversationStatus::Accepted,
            createdAt: $now->modify('-10 days'),
            respondedAt: $now->modify('-10 days'),
            lastMessageAt: $now->modify('-1 day'),
        );
        $manager->persist($acceptedConversation);
        $this->addReference(self::CONVERSATION_ACCEPTED, $acceptedConversation);

        // Pending conversation: WITH_STRIPE (initiator) → REGULAR (recipient)
        $pendingConversation = new Conversation(
            id: Uuid::fromString(self::CONVERSATION_PENDING),
            initiator: $playerWithStripe,
            recipient: $playerRegular,
            status: ConversationStatus::Pending,
            createdAt: $now->modify('-2 days'),
            lastMessageAt: $now->modify('-2 days'),
        );
        $manager->persist($pendingConversation);
        $this->addReference(self::CONVERSATION_PENDING, $pendingConversation);

        // Marketplace conversation: WITH_FAVORITES (initiator) → WITH_STRIPE (recipient), linked to SELLSWAP_01
        $marketplaceConversation = new Conversation(
            id: Uuid::fromString(self::CONVERSATION_MARKETPLACE),
            initiator: $playerWithFavorites,
            recipient: $playerWithStripe,
            status: ConversationStatus::Accepted,
            createdAt: $now->modify('-5 days'),
            sellSwapListItem: $sellSwapItem01,
            puzzle: $puzzle500_01,
            respondedAt: $now->modify('-5 days'),
            lastMessageAt: $now->modify('-3 days'),
        );
        $manager->persist($marketplaceConversation);
        $this->addReference(self::CONVERSATION_MARKETPLACE, $marketplaceConversation);

        // Ignored conversation: WITH_FAVORITES (initiator) → ADMIN (recipient)
        $ignoredConversation = new Conversation(
            id: Uuid::fromString(self::CONVERSATION_IGNORED),
            initiator: $playerWithFavorites,
            recipient: $playerAdmin,
            status: ConversationStatus::Ignored,
            createdAt: $now->modify('-15 days'),
            respondedAt: $now->modify('-14 days'),
        );
        $manager->persist($ignoredConversation);
        $this->addReference(self::CONVERSATION_IGNORED, $ignoredConversation);

        $manager->flush();
    }

    /**
     * @return array<class-string<Fixture>>
     */
    public function getDependencies(): array
    {
        return [
            PlayerFixture::class,
            SellSwapListItemFixture::class,
            PuzzleFixture::class,
        ];
    }
}
