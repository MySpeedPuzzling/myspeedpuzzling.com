<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\OneToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

#[Entity]
class Membership
{
    #[Column(nullable: true)]
    public null|DateTimeImmutable $endsAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,

        #[OneToOne]
        #[JoinColumn(nullable: false)]
        #[Immutable]
        public Player $player,

        #[Column(nullable: true)]
        public null|string $stripeSubscriptionId = null,

        #[Column(nullable: true)]
        public null|DateTimeImmutable $billingPeriodEndsAt = null,
    ) {
    }

    public function renewStripeSubscription(
        string $stripeSubscriptionId,
        DateTimeImmutable $billingPeriodEndsAt,
    ): void
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->billingPeriodEndsAt = $billingPeriodEndsAt;
        $this->endsAt = null;
    }
}
