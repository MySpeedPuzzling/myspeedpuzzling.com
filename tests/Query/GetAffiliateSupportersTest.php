<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAffiliateSupporters;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetAffiliateSupportersTest extends KernelTestCase
{
    private GetAffiliateSupporters $getAffiliateSupporters;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->getAffiliateSupporters = self::getContainer()->get(GetAffiliateSupporters::class);
    }

    public function testReturnsTotalCountIncludingPrivateProfiles(): void
    {
        $result = $this->getAffiliateSupporters->byAffiliateId(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        // PLAYER_PRIVATE is a supporter but has a private profile
        self::assertSame(1, $result['total_count']);
    }

    public function testPrivateProfilesExcludedFromPublicList(): void
    {
        $result = $this->getAffiliateSupporters->byAffiliateId(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        // PLAYER_PRIVATE has is_private=true, so should not appear in public list
        // Total is 1 (the private player), but public list should be empty
        self::assertSame(1, $result['total_count']);
        self::assertEmpty($result['public_supporters']);
    }

    public function testReturnsEmptyForAffiliateWithNoSupporters(): void
    {
        $result = $this->getAffiliateSupporters->byAffiliateId(AffiliateFixture::AFFILIATE_PENDING_ID);

        self::assertSame(0, $result['total_count']);
        self::assertEmpty($result['public_supporters']);
    }
}
