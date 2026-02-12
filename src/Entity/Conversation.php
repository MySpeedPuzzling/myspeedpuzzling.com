<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ConversationStatus;

#[Entity]
#[UniqueConstraint(columns: ['initiator_id', 'recipient_id', 'sell_swap_list_item_id'])]
#[Index(columns: ['initiator_id'])]
#[Index(columns: ['recipient_id'])]
#[Index(columns: ['status'])]
#[Index(columns: ['last_message_at'])]
class Conversation
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $initiator,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $recipient,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::STRING, enumType: ConversationStatus::class)]
        public ConversationStatus $status,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
        public null|SellSwapListItem $sellSwapListItem = null,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|Puzzle $puzzle = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $respondedAt = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $lastMessageAt = null,
    ) {
    }

    public function accept(): void
    {
        $this->status = ConversationStatus::Accepted;
        $this->respondedAt = new DateTimeImmutable();
    }

    public function deny(): void
    {
        $this->status = ConversationStatus::Denied;
        $this->respondedAt = new DateTimeImmutable();
    }

    public function updateLastMessageAt(DateTimeImmutable $at): void
    {
        $this->lastMessageAt = $at;
    }
}
