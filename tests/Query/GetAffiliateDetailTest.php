<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAffiliateDetail;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAffiliateDetailTest extends KernelTestCase
{
    private GetAffiliateDetail $getAffiliateDetail;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAffiliateDetail = self::getContainer()->get(GetAffiliateDetail::class);
    }

    public function testOverviewReturnsAffiliateWithStats(): void
    {
        $result = $this->getAffiliateDetail->overview(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        self::assertNotNull($result);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_CODE, $result->code);
        self::assertSame(AffiliateStatus::Active, $result->status);
        self::assertSame(1, $result->supporterCount);
    }

    public function testOverviewReturnsNullForInvalidId(): void
    {
        $result = $this->getAffiliateDetail->overview('00000000-0000-0000-0000-000000000099');

        self::assertNull($result);
    }

    public function testReferralsReturnsListForAffiliate(): void
    {
        $referrals = $this->getAffiliateDetail->referrals(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        self::assertCount(1, $referrals);
        self::assertSame('link', $referrals[0]->source->value);
    }

    public function testReferralsReturnsEmptyForAffiliateWithNoReferrals(): void
    {
        $referrals = $this->getAffiliateDetail->referrals(AffiliateFixture::AFFILIATE_PENDING_ID);

        self::assertCount(0, $referrals);
    }

    public function testPayoutsReturnsListForAffiliate(): void
    {
        $payouts = $this->getAffiliateDetail->payouts(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        self::assertCount(2, $payouts);
        // Should be ordered by created_at DESC
        self::assertSame('in_test_pending_001', $payouts[0]->stripeInvoiceId);
        self::assertSame('in_test_paid_001', $payouts[1]->stripeInvoiceId);
    }

    public function testPayoutsReturnsEmptyForAffiliateWithNoPayouts(): void
    {
        $payouts = $this->getAffiliateDetail->payouts(AffiliateFixture::AFFILIATE_PENDING_ID);

        self::assertCount(0, $payouts);
    }
}
