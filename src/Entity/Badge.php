<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

#[Entity]
class Badge
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[ManyToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable]
        public Player $player,
        #[Column]
        #[Immutable]
        public BadgeType $type,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $earnedAt,
        #[Column(type: Types::SMALLINT, nullable: true)]
        #[Immutable]
        public null|int $tier = null,
    ) {
    }

    public static function earn(
        Player $player,
        BadgeType $type,
        DateTimeImmutable $earnedAt,
        null|BadgeTier $tier,
    ): self {
        return new self(
            id: Uuid::uuid7(),
            player: $player,
            type: $type,
            earnedAt: $earnedAt,
            tier: $tier?->value,
        );
    }
}
