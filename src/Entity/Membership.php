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
use SpeedPuzzling\Web\Events\MembershipTrialEnded;
use Stripe\Subscription;

#[Entity]
class Membership implements EntityWithEvents
{
    use HasEvents;

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
        #[Column(nullable: true)]
        public null|DateTimeImmutable $endsAt = null,
    ) {
        $this->recordThat(new MembershipStarted($this->id));
    }

    public function updateStripeSubscription(
        string $stripeSubscriptionId,
        DateTimeImmutable $billingPeriodEndsAt,
        string $status,
        DateTimeImmutable $now,
    ): void {
        if ($status === Subscription::STATUS_CANCELED) {
            $this->cancel($billingPeriodEndsAt);
        }

        if (
            $status === Subscription::STATUS_INCOMPLETE ||
            $status === Subscription::STATUS_INCOMPLETE_EXPIRED ||
            $status === Subscription::STATUS_UNPAID
        ) {
            $this->endsAt = $now;
            $this->billingPeriodEndsAt = $billingPeriodEndsAt;
        }

        if ($status === Subscription::STATUS_PAUSED) {
            $this->endsAt = $now;
            $this->billingPeriodEndsAt = $billingPeriodEndsAt;
            $this->recordThat(new MembershipTrialEnded($this->id));
        }

        // TODO: Split active vs trial
        if ($status === Subscription::STATUS_ACTIVE || $status === Subscription::STATUS_TRIALING) {
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
