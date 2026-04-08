<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PayoutStatus;

readonly final class PayoutOverview
{
    public function __construct(
        public string $payoutId,
        public string $affiliateId,
        public null|string $affiliatePlayerName,
        public string $subscriberId,
        public null|string $subscriberName,
        public string $stripeInvoiceId,
        public int $paymentAmountCents,
        public int $payoutAmountCents,
        public string $currency,
        public PayoutStatus $status,
        public DateTimeImmutable $createdAt,
        public null|DateTimeImmutable $paidAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $payoutId = $row['payout_id'];
        assert(is_string($payoutId));
        $affiliateId = $row['affiliate_id'];
        assert(is_string($affiliateId));
        $subscriberId = $row['subscriber_id'];
        assert(is_string($subscriberId));
        $stripeInvoiceId = $row['stripe_invoice_id'];
        assert(is_string($stripeInvoiceId));
        $currency = $row['currency'];
        assert(is_string($currency));
        $createdAt = $row['created_at'];
        assert(is_string($createdAt));

        $statusString = $row['status'];
        assert(is_string($statusString));

        $paidAt = $row['paid_at'] ?? null;
        assert(is_string($paidAt) || $paidAt === null);

        /** @var int|string $paymentAmountCents */
        $paymentAmountCents = $row['payment_amount_cents'];
        /** @var int|string $payoutAmountCents */
        $payoutAmountCents = $row['payout_amount_cents'];

        return new self(
            payoutId: $payoutId,
            affiliateId: $affiliateId,
            affiliatePlayerName: is_string($row['affiliate_player_name'] ?? null) ? $row['affiliate_player_name'] : null,
            subscriberId: $subscriberId,
            subscriberName: is_string($row['subscriber_name'] ?? null) ? $row['subscriber_name'] : null,
            stripeInvoiceId: $stripeInvoiceId,
            paymentAmountCents: (int) $paymentAmountCents,
            payoutAmountCents: (int) $payoutAmountCents,
            currency: $currency,
            status: PayoutStatus::from($statusString),
            createdAt: new DateTimeImmutable($createdAt),
            paidAt: $paidAt !== null ? new DateTimeImmutable($paidAt) : null,
        );
    }
}
