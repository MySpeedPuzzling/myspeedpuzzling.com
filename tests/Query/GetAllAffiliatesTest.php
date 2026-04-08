<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAllAffiliates;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAllAffiliatesTest extends KernelTestCase
{
    private GetAllAffiliates $getAllAffiliates;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAllAffiliates = self::getContainer()->get(GetAllAffiliates::class);
    }

    public function testCountByStatus(): void
    {
        $counts = $this->getAllAffiliates->countByStatus();

        self::assertSame(1, $counts['pending']);
        self::assertSame(1, $counts['active']);
        self::assertSame(1, $counts['suspended']);
    }

    public function testFiltersByStatusCorrectly(): void
    {
        $active = $this->getAllAffiliates->byStatus(AffiliateStatus::Active);
        self::assertCount(1, $active);

        $pending = $this->getAllAffiliates->byStatus(AffiliateStatus::Pending);
        self::assertCount(1, $pending);

        $suspended = $this->getAllAffiliates->byStatus(AffiliateStatus::Suspended);
        self::assertCount(1, $suspended);
    }

    public function testIncludesCountsPerAffiliate(): void
    {
        $active = $this->getAllAffiliates->byStatus(AffiliateStatus::Active);

        self::assertSame(1, $active[0]->supporterCount);
        self::assertSame(120, $active[0]->totalEarnedCents);
    }
}
