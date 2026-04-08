<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Affiliate;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Value\PayoutStatus;

final class AffiliatePayoutTest extends TestCase
{
    public function testNewPayoutHasPendingStatus(): void
    {
        $payout = new AffiliatePayout(
            id: Uuid::uuid7(),
            affiliate: $this->createMock(Affiliate::class),
            referral: $this->createMock(Referral::class),
            stripeInvoiceId: 'in_test_123',
            paymentAmountCents: 600,
            payoutAmountCents: 60,
            currency: 'EUR',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(PayoutStatus::Pending, $payout->status);
        self::assertNull($payout->paidAt);
    }

    public function testMarkAsPaidSetsStatusAndTimestamp(): void
    {
        $payout = new AffiliatePayout(
            id: Uuid::uuid7(),
            affiliate: $this->createMock(Affiliate::class),
            referral: $this->createMock(Referral::class),
            stripeInvoiceId: 'in_test_123',
            paymentAmountCents: 600,
            payoutAmountCents: 60,
            currency: 'EUR',
            createdAt: new DateTimeImmutable(),
        );

        $paidAt = new DateTimeImmutable('2026-03-15');
        $payout->markAsPaid($paidAt);

        self::assertSame(PayoutStatus::Paid, $payout->status);
        self::assertSame($paidAt, $payout->paidAt);
    }

    public function testPayoutAmountsAreStoredCorrectly(): void
    {
        $payout = new AffiliatePayout(
            id: Uuid::uuid7(),
            affiliate: $this->createMock(Affiliate::class),
            referral: $this->createMock(Referral::class),
            stripeInvoiceId: 'in_test_456',
            paymentAmountCents: 15000,
            payoutAmountCents: 1500,
            currency: 'CZK',
            createdAt: new DateTimeImmutable(),
        );

        self::assertSame(15000, $payout->paymentAmountCents);
        self::assertSame(1500, $payout->payoutAmountCents);
        self::assertSame('CZK', $payout->currency);
    }
}
