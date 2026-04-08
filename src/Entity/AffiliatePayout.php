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
use SpeedPuzzling\Web\Value\PayoutStatus;

#[Entity]
class AffiliatePayout
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::STRING, enumType: PayoutStatus::class, options: ['default' => 'pending'])]
    public PayoutStatus $status;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $paidAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Affiliate $affiliate,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Tribute $tribute,
        #[Immutable]
        #[Column(length: 64, unique: true)]
        public string $stripeInvoiceId,
        #[Immutable]
        #[Column]
        public int $paymentAmountCents,
        #[Immutable]
        #[Column]
        public int $payoutAmountCents,
        #[Immutable]
        #[Column(length: 3)]
        public string $currency,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        PayoutStatus $status = PayoutStatus::Pending,
    ) {
        $this->status = $status;
    }

    public function markAsPaid(DateTimeImmutable $paidAt): void
    {
        $this->status = PayoutStatus::Paid;
        $this->paidAt = $paidAt;
    }
}
