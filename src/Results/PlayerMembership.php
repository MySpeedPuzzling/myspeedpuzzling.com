<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PlayerMembership
{
    public function __construct(
        public null|string $stripeSubscriptionId,
        public null|DateTimeImmutable $endsAt,
        public null|DateTimeImmutable $billingPeriodEndsAt,
    ) {
    }

    /**
     * @param array{
     *     stripe_subscription_id: null|string,
     *     ends_at: null|string,
     *     billing_period_ends_at: null|string,
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

        return new self(
            stripeSubscriptionId: $row['stripe_subscription_id'],
            endsAt: $endsAt,
            billingPeriodEndsAt: $billingPeriodEndsAt,
        );
    }
}
