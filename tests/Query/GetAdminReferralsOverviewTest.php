<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAdminReferralsOverview;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAdminReferralsOverviewTest extends KernelTestCase
{
    private GetAdminReferralsOverview $getAdminReferralsOverview;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAdminReferralsOverview = self::getContainer()->get(GetAdminReferralsOverview::class);
    }

    public function testTotalsPerCurrency(): void
    {
        $totals = $this->getAdminReferralsOverview->totalsPerCurrency();

        self::assertCount(1, $totals);
        self::assertSame('EUR', $totals[0]['currency']);
        self::assertSame(60, $totals[0]['unpaid_cents']);
        self::assertSame(60, $totals[0]['paid_cents']);
    }

    public function testAffiliatesContainsAllProgramMembers(): void
    {
        $affiliates = $this->getAdminReferralsOverview->affiliates();

        self::assertCount(2, $affiliates);

        $playerIds = array_column($affiliates, 'player_id');
        self::assertContains(PlayerFixture::PLAYER_REGULAR, $playerIds);
        self::assertContains(PlayerFixture::PLAYER_WITH_STRIPE, $playerIds);
    }

    public function testAffiliatesWithUnpaidCommissionComeFirst(): void
    {
        $affiliates = $this->getAdminReferralsOverview->affiliates();

        self::assertSame(PlayerFixture::PLAYER_REGULAR, $affiliates[0]['player_id']);
        self::assertSame(1, $affiliates[0]['referral_count']);
        self::assertSame(60, $affiliates[0]['unpaid_total_cents']);
        self::assertFalse($affiliates[0]['referral_program_suspended']);

        self::assertCount(1, $affiliates[0]['payouts']);
        self::assertSame('EUR', $affiliates[0]['payouts'][0]['currency']);
        self::assertSame(60, $affiliates[0]['payouts'][0]['unpaid_cents']);
        self::assertSame(60, $affiliates[0]['payouts'][0]['paid_cents']);
    }

    public function testAffiliateWithoutReferralsHasEmptyPayouts(): void
    {
        $affiliates = $this->getAdminReferralsOverview->affiliates();

        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $affiliates[1]['player_id']);
        self::assertSame(0, $affiliates[1]['referral_count']);
        self::assertSame(0, $affiliates[1]['unpaid_total_cents']);
        self::assertSame([], $affiliates[1]['payouts']);
        self::assertTrue($affiliates[1]['referral_program_suspended']);
    }
}
