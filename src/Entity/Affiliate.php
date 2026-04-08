<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\AffiliateStatus;

#[Entity]
class Affiliate
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: AffiliateStatus::class, options: ['default' => 'pending'])]
    public AffiliateStatus $status;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[OneToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        #[Immutable]
        public Player $player,
        #[Immutable]
        #[Column(length: 8, unique: true)]
        public string $code,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        AffiliateStatus $status = AffiliateStatus::Pending,
    ) {
        $this->status = $status;
    }

    public function approve(): void
    {
        $this->status = AffiliateStatus::Active;
    }

    public function suspend(): void
    {
        $this->status = AffiliateStatus::Suspended;
    }

    public function reactivate(): void
    {
        $this->status = AffiliateStatus::Active;
    }

    public function isActive(): bool
    {
        return $this->status === AffiliateStatus::Active;
    }
}
