<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Voucher
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|DateTimeImmutable $usedAt = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public null|Player $usedBy = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(length: 32, unique: true)]
        public string $code,
        #[Immutable]
        #[Column]
        public int $monthsValue,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validUntil,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[Column(type: 'text', nullable: true)]
        public null|string $internalNote = null,
    ) {
    }

    public function markAsUsed(Player $player, DateTimeImmutable $usedAt): void
    {
        $this->usedBy = $player;
        $this->usedAt = $usedAt;
    }

    public function isUsed(): bool
    {
        return $this->usedAt !== null;
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $now > $this->validUntil;
    }

    public function isAvailable(DateTimeImmutable $now): bool
    {
        return !$this->isUsed() && !$this->isExpired($now);
    }
}
