<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Repository;

use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ReferralRepositoryTest extends KernelTestCase
{
    private ReferralRepository $referralRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->referralRepository = self::getContainer()->get(ReferralRepository::class);
    }

    public function testGetById(): void
    {
        $referral = $this->referralRepository->get(AffiliateFixture::REFERRAL_ID);

        self::assertSame(PlayerFixture::PLAYER_PRIVATE, $referral->subscriber->id->toString());
    }

    public function testGetByIdThrowsForInvalidId(): void
    {
        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->get('00000000-0000-0000-0000-000000000099');
    }

    public function testGetBySubscriberId(): void
    {
        $referral = $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_PRIVATE);

        self::assertSame(AffiliateFixture::REFERRAL_ID, $referral->id->toString());
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $referral->affiliatePlayer->id->toString());
    }

    public function testGetBySubscriberIdThrowsForNonReferredPlayer(): void
    {
        $this->expectException(ReferralNotFound::class);
        $this->referralRepository->getBySubscriberId(PlayerFixture::PLAYER_ADMIN);
    }
}
