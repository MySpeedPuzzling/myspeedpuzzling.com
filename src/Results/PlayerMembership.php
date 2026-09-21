<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\LifetimeMembership;

readonly final class PlayerMembership
{
    public function __construct(
        public null|string $stripeSubscriptionId,
        public null|DateTimeImmutable $endsAt,
        public null|DateTimeImmutable $billingPeriodEndsAt,
        public null|DateTimeImmutable $grantedUntil,
        /** Percentage discount from a voucher, currently on the Stripe subscription */
        public null|int $discountPercent = null,
        public null|string $discountVoucherCode = null,
        /** End of the free trial (docs/features/free-trial/README.md) - kept after it ran out */
        public null|DateTimeImmutable $trialEndsAt = null,
    ) {
    }

    /**
     * The free trial is what the membership runs on right now. A player who subscribes during the trial
     * is a subscriber from then on - the remaining days become a Stripe trial of that subscription.
     */
    public function isInFreeTrial(DateTimeImmutable $now): bool
    {
        return $this->trialEndsAt !== null
            && $this->trialEndsAt > $now
            && $this->billingPeriodEndsAt === null;
    }

    public function freeTrialDaysLeft(DateTimeImmutable $now): int
    {
        if ($this->trialEndsAt === null || $this->trialEndsAt <= $now) {
            return 0;
        }

        return (int) ceil(($this->trialEndsAt->getTimestamp() - $now->getTimestamp()) / 86400);
    }

    /**
     * Checkout turns whole remaining days into a Stripe trial (MembershipManagement) - with less than
     * a day left there is nothing to carry over and the first payment is taken right away.
     */
    public function keepsFreeTrialDaysOnSubscribe(DateTimeImmutable $now): bool
    {
        return $this->grantedUntil !== null && $now->diff($this->grantedUntil)->days > 0 && $this->grantedUntil > $now;
    }

    /**
     * Tried membership for free, never anything else, and it is over.
     */
    public function isEndedFreeTrialOnly(DateTimeImmutable $now): bool
    {
        return $this->trialEndsAt !== null
            && $this->stripeSubscriptionId === null
            && $this->isActive($now) === false;
    }

    public function isActive(DateTimeImmutable $now): bool
    {
        if ($this->endsAt === null && $this->billingPeriodEndsAt !== null) {
            return true;
        }

        if ($this->endsAt !== null && $this->endsAt > $now) {
            return true;
        }

        return $this->grantedUntil !== null && $this->grantedUntil > $now;
    }

    public function hasActiveGrant(DateTimeImmutable $now): bool
    {
        return $this->grantedUntil !== null && $this->grantedUntil > $now;
    }

    /**
     * The last day of a membership that does not renew - whichever of a cancelled subscription's
     * paid period and a free grant (e.g. from a voucher) reaches further.
     */
    public function activeUntil(DateTimeImmutable $now): null|DateTimeImmutable
    {
        $endsAt = $this->endsAt !== null && $this->endsAt > $now ? $this->endsAt : null;
        $grantedUntil = $this->hasActiveGrant($now) ? $this->grantedUntil : null;

        if ($endsAt === null || $grantedUntil === null) {
            return $endsAt ?? $grantedUntil;
        }

        return max($endsAt, $grantedUntil);
    }

    public function hasLifetimeGrant(): bool
    {
        return LifetimeMembership::isLifetime($this->grantedUntil);
    }

    /**
     * @param array{
     *     stripe_subscription_id: null|string,
     *     ends_at: null|string,
     *     billing_period_ends_at: null|string,
     *     granted_until: null|string,
     *     trial_ends_at?: null|string,
     *     discount_percent: null|int,
     *     discount_voucher_code: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $endsAt = null;
        if ($row['ends_at'] !== null) {
            $endsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['ends_at']);
            assert($endsAt instanceof DateTimeImmutable);
        }

        $billingPeriodEndsAt = null;
        if ($row['billing_period_ends_at'] !== null) {
            $billingPeriodEndsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['billing_period_ends_at']);
            assert($billingPeriodEndsAt instanceof DateTimeImmutable);
        }

        $grantedUntil = null;
        if ($row['granted_until'] !== null) {
            $grantedUntil = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['granted_until']);
            assert($grantedUntil instanceof DateTimeImmutable);
        }

        $trialEndsAt = null;
        if (($row['trial_ends_at'] ?? null) !== null) {
            $trialEndsAt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $row['trial_ends_at']);
            assert($trialEndsAt instanceof DateTimeImmutable);
        }

        return new self(
            stripeSubscriptionId: $row['stripe_subscription_id'],
            endsAt: $endsAt,
            billingPeriodEndsAt: $billingPeriodEndsAt,
            grantedUntil: $grantedUntil,
            discountPercent: $row['discount_percent'],
            discountVoucherCode: $row['discount_voucher_code'],
            trialEndsAt: $trialEndsAt,
        );
    }
}
