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
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\ModerationActionType;

#[Entity]
#[Index(columns: ['target_player_id'])]
#[Index(columns: ['performed_at'])]
class ModerationAction
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $targetPlayer,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        public Player $admin,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: ModerationActionType::class)]
        public ModerationActionType $actionType,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $performedAt,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: true)]
        public null|ConversationReport $report = null,
        #[Immutable]
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason = null,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $expiresAt = null,
    ) {
    }
}
