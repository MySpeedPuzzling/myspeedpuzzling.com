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
    ) {
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

        return new self(
            stripeSubscriptionId: $row['stripe_subscription_id'],
            endsAt: $endsAt,
            billingPeriodEndsAt: $billingPeriodEndsAt,
            grantedUntil: $grantedUntil,
            discountPercent: $row['discount_percent'],
            discountVoucherCode: $row['discount_voucher_code'],
        );
    }
}
