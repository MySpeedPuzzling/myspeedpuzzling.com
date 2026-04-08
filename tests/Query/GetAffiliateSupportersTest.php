<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Query\GetAffiliateSupporters;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
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
        $result = $this->getAffiliateSupporters->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        // PLAYER_PRIVATE is a supporter but has a private profile
        self::assertSame(1, $result['total_count']);
    }

    public function testPrivateProfilesExcludedFromPublicList(): void
    {
        $result = $this->getAffiliateSupporters->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(1, $result['total_count']);
        self::assertEmpty($result['public_supporters']);
    }

    public function testReturnsPayoutStats(): void
    {
        $result = $this->getAffiliateSupporters->byPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(120, $result['total_earned_cents']); // 60 + 60
        self::assertSame(60, $result['pending_payout_cents']); // only the pending one
    }

    public function testReturnsEmptyForPlayerWithNoSupporters(): void
    {
        $result = $this->getAffiliateSupporters->byPlayerId(PlayerFixture::PLAYER_ADMIN);

        self::assertSame(0, $result['total_count']);
        self::assertEmpty($result['public_supporters']);
        self::assertSame(0, $result['total_earned_cents']);
    }
}
