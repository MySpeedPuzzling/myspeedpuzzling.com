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
use SpeedPuzzling\Web\Value\FreeTrial;
use SpeedPuzzling\Web\Value\FreeTrialSource;
use SpeedPuzzling\Web\Value\LifetimeMembership;
use SpeedPuzzling\Web\Value\Platform;
use Stripe\Subscription;

#[Entity]
class Membership implements EntityWithEvents
{
    use HasEvents;

    /**
     * The coupon currently on the Stripe subscription, as Stripe reports it on every subscription webhook -
     * a percentage voucher's discount lives there, and disappears with the subscription it was applied to.
     */
    #[Column(nullable: true)]
    public null|string $stripeDiscountCouponId = null;

    /**
     * The free trial (docs/features/free-trial/README.md): set once, by startFreeTrial(), and never
     * cleared - together with the membership row itself they are why a trial cannot be had twice.
     * `grantedUntil` may later move past `trialEndsAt` (a voucher claimed during the trial), so the
     * trial keeps its own end.
     */
    #[Column(nullable: true)]
    public null|DateTimeImmutable $trialStartedAt = null;

    #[Column(nullable: true)]
    public null|DateTimeImmutable $trialEndsAt = null;

    #[Column(length: 32, nullable: true, enumType: FreeTrialSource::class)]
    public null|FreeTrialSource $trialSource = null;

    #[Column(nullable: true)]
    public null|DateTimeImmutable $trialEndingReminderSentAt = null;

    /** The first subscription of a player who had the trial - during it or any time later. Funnel only. */
    #[Column(nullable: true)]
    public null|DateTimeImmutable $trialConvertedAt = null;

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
        #[Column(length: 10, options: ['default' => 'web'])]
        public Platform $platform = Platform::Web,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $grantedUntil = null,
        #[Column(nullable: true)]
        public null|DateTimeImmutable $renewedBillingPeriodEnd = null,
    ) {
        $this->recordThat(new MembershipStarted($this->id));
    }

    public static function startFreeTrial(
        UuidInterface $id,
        Player $player,
        DateTimeImmutable $now,
        FreeTrialSource $source,
    ): self {
        $trialEndsAt = FreeTrial::endsAt($now);

        $membership = new self($id, $player, $now, grantedUntil: $trialEndsAt);
        $membership->trialStartedAt = $now;
        $membership->trialEndsAt = $trialEndsAt;
        $membership->trialSource = $source;

        return $membership;
    }

    public function isFreeTrial(): bool
    {
        return $this->trialEndsAt !== null;
    }

    public function isManagedByAppStore(): bool
    {
        return $this->platform === Platform::Ios;
    }

    public function isManagedByPlayStore(): bool
    {
        return $this->platform === Platform::Android;
    }

    public function isManagedByStripe(): bool
    {
        return $this->platform === Platform::Web;
    }

    public function hasLifetimeGrant(): bool
    {
        return LifetimeMembership::isLifetime($this->grantedUntil);
    }

    public function grantLifetime(): void
    {
        $this->grantedUntil = LifetimeMembership::grantedUntil();
    }

    public function updateStripeSubscription(
        string $stripeSubscriptionId,
        DateTimeImmutable $billingPeriodEndsAt,
        string $status,
        DateTimeImmutable $now,
        bool $isPaymentConfirmed = false,
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

        if ($status === Subscription::STATUS_PAST_DUE) {
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
            if ($this->trialEndsAt !== null && $this->trialConvertedAt === null) {
                $this->trialConvertedAt = $now;
            }

            $this->stripeSubscriptionId = $stripeSubscriptionId;
            $this->endsAt = null;

            if (
                $this->billingPeriodEndsAt === null
                || $billingPeriodEndsAt > $this->billingPeriodEndsAt
            ) {
                $this->billingPeriodEndsAt = $billingPeriodEndsAt;
            }

            if ($isPaymentConfirmed && $billingPeriodEndsAt != $this->renewedBillingPeriodEnd) {
                $this->recordThat(new MembershipSubscriptionRenewed($this->id));
                $this->renewedBillingPeriodEnd = $billingPeriodEndsAt;
            }
        }
    }

    public function cancel(DateTimeImmutable $billingPeriodEndsAt): void
    {
        // A cancel-at-period-end reaches us twice: once when the player clicks cancel, and again as
        // `customer.subscription.deleted` when the period runs out. Only the first is news to the player.
        // Clearing `billingPeriodEndsAt` below is what marks a cancellation as already announced - a
        // subscription that merely stopped paying keeps it set (see updateStripeSubscription) and never
        // recorded the event, so it still gets its notice when Stripe finally deletes it.
        if ($this->endsAt === null || $this->billingPeriodEndsAt !== null) {
            $this->recordThat(new MembershipSubscriptionCancelled($this->id));
        }

        // Never hand back access somebody has already lost. Stripe reports a cancelled subscription with
        // the paid period still running, so a late or out-of-order webhook would otherwise push `endsAt`
        // back into the future - including for a player whose payment we just saw reversed.
        if ($this->endsAt === null || $billingPeriodEndsAt < $this->endsAt) {
            $this->endsAt = $billingPeriodEndsAt;
        }

        $this->billingPeriodEndsAt = null;
    }
}
