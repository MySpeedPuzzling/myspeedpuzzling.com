<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetAdminReferralDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAdminReferralDetailTest extends KernelTestCase
{
    private GetAdminReferralDetail $getAdminReferralDetail;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAdminReferralDetail = self::getContainer()->get(GetAdminReferralDetail::class);
    }

    public function testAffiliate(): void
    {
        $affiliate = $this->getAdminReferralDetail->affiliate(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $affiliate['player_id']);
        self::assertNotNull($affiliate['referral_program_joined_at']);
        self::assertFalse($affiliate['referral_program_suspended']);
    }

    public function testAffiliateNotFound(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->getAdminReferralDetail->affiliate('019f9999-9999-9999-9999-999999999999');
    }

    public function testAffiliateWithInvalidUuid(): void
    {
        $this->expectException(PlayerNotFound::class);

        $this->getAdminReferralDetail->affiliate('not-a-uuid');
    }

    public function testPayoutTotals(): void
    {
        $totals = $this->getAdminReferralDetail->payoutTotals(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $totals);
        self::assertSame('EUR', $totals[0]['currency']);
        self::assertSame(60, $totals[0]['unpaid_cents']);
        self::assertSame(60, $totals[0]['paid_cents']);
    }

    public function testPayoutTotalsEmptyForAffiliateWithoutPayouts(): void
    {
        self::assertSame([], $this->getAdminReferralDetail->payoutTotals(PlayerFixture::PLAYER_WITH_STRIPE));
    }

    public function testReferrals(): void
    {
        $referrals = $this->getAdminReferralDetail->referrals(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(1, $referrals);
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $referrals[0]['subscriber_id']);
        self::assertSame('link', $referrals[0]['source']);
        self::assertFalse($referrals[0]['has_active_subscription']);

        self::assertCount(1, $referrals[0]['payments']);
        self::assertSame('EUR', $referrals[0]['payments'][0]['currency']);
        self::assertSame(2, $referrals[0]['payments'][0]['payment_count']);
        self::assertSame(120, $referrals[0]['payments'][0]['commission_cents']);
        self::assertSame(60, $referrals[0]['payments'][0]['unpaid_cents']);
    }

    public function testPayoutsAreOrderedNewestFirst(): void
    {
        $payouts = $this->getAdminReferralDetail->payouts(PlayerFixture::PLAYER_REGULAR);

        self::assertCount(2, $payouts);

        self::assertSame('in_test_pending_001', $payouts[0]['stripe_invoice_id']);
        self::assertSame('pending', $payouts[0]['status']);
        self::assertNull($payouts[0]['paid_at']);
        self::assertSame(600, $payouts[0]['payment_amount_cents']);
        self::assertSame(60, $payouts[0]['payout_amount_cents']);
        self::assertSame('EUR', $payouts[0]['currency']);
        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $payouts[0]['subscriber_id']);

        self::assertSame('in_test_paid_001', $payouts[1]['stripe_invoice_id']);
        self::assertSame('paid', $payouts[1]['status']);
        self::assertNotNull($payouts[1]['paid_at']);
    }

    public function testPayoutsEmptyForAffiliateWithoutPayouts(): void
    {
        self::assertSame([], $this->getAdminReferralDetail->payouts(PlayerFixture::PLAYER_WITH_STRIPE));
    }
}
