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
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\VoucherType;

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

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|string $stripeCouponId = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(length: 32, unique: true)]
        public string $code,
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $monthsValue,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $validUntil,
        #[Immutable]
        #[Column]
        public DateTimeImmutable $createdAt,
        #[Immutable]
        #[Column(type: 'text', nullable: true)]
        public null|string $internalNote = null,
        #[Immutable]
        #[Column(type: Types::STRING, enumType: VoucherType::class, options: ['default' => 'free_months'])]
        public VoucherType $voucherType = VoucherType::FreeMonths,
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $percentageDiscount = null,
        #[Immutable]
        #[Column(options: ['default' => 1])]
        public int $maxUses = 1,
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

    public function isFreeMonths(): bool
    {
        return $this->voucherType === VoucherType::FreeMonths;
    }

    public function isPercentageDiscount(): bool
    {
        return $this->voucherType === VoucherType::PercentageDiscount;
    }

    public function hasRemainingUses(int $currentUsageCount): bool
    {
        return $currentUsageCount < $this->maxUses;
    }

    public function setStripeCouponId(string $couponId): void
    {
        $this->stripeCouponId = $couponId;
    }
}
