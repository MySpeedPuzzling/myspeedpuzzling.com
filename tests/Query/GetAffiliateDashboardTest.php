<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAffiliateDashboard;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAffiliateDashboardTest extends KernelTestCase
{
    private GetAffiliateDashboard $getAffiliateDashboard;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAffiliateDashboard = self::getContainer()->get(GetAffiliateDashboard::class);
    }

    public function testReturnsActiveAffiliateWithStats(): void
    {
        $result = $this->getAffiliateDashboard->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertNotNull($result);
        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_CODE, $result->code);
        self::assertSame(AffiliateStatus::Active, $result->status);
        self::assertSame(1, $result->supporterCount);
        self::assertSame(120, $result->totalEarnedCents); // 60 + 60
        self::assertSame(60, $result->pendingPayoutCents); // only the pending one
    }

    public function testReturnsNullForNonAffiliate(): void
    {
        // PLAYER_ADMIN is not an affiliate
        $result = $this->getAffiliateDashboard->byPlayerId(PlayerFixture::PLAYER_ADMIN);

        self::assertNull($result);
    }

    public function testReturnsPendingAffiliate(): void
    {
        $result = $this->getAffiliateDashboard->byPlayerId(PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertNotNull($result);
        self::assertSame(AffiliateStatus::Pending, $result->status);
        self::assertSame(0, $result->supporterCount);
        self::assertSame(0, $result->totalEarnedCents);
    }
}
