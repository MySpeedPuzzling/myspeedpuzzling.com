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
use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Events\MembershipStarted;
use SpeedPuzzling\Web\Events\MembershipSubscriptionRenewed;

#[Entity]
class Membership implements EntityWithEvents
{
    use HasEvents;

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

        #[Column]
        public DateTimeImmutable $createdAt,

        #[Column(nullable: true)]
        public null|string $stripeSubscriptionId = null,

        #[Column(nullable: true)]
        public null|DateTimeImmutable $billingPeriodEndsAt = null,
    ) {
        $this->recordThat(new MembershipStarted($this->id));
    }

    public function updateStripeSubscription(
        string $stripeSubscriptionId,
        DateTimeImmutable $billingPeriodEndsAt,
    ): void
    {
        if (
            $this->billingPeriodEndsAt === null
            || $this->endsAt !== null
            || $billingPeriodEndsAt > $this->billingPeriodEndsAt
        ) {
            $this->recordThat(new MembershipSubscriptionRenewed($this->id));
        }

        $this->stripeSubscriptionId = $stripeSubscriptionId;
        $this->billingPeriodEndsAt = $billingPeriodEndsAt;
        $this->endsAt = null;
    }

    public function cancel(DateTimeImmutable $billingPeriodEndsAt): void
    {
        if ($this->endsAt === null || $billingPeriodEndsAt > $this->endsAt) {
            $this->recordThat(new MembershipSubscriptionCancelled($this->id));
        }

        $this->endsAt = $billingPeriodEndsAt;
        $this->billingPeriodEndsAt = null;
    }
}
