<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Repository;

use SpeedPuzzling\Web\Exceptions\AffiliateNotFound;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AffiliateRepositoryTest extends KernelTestCase
{
    private AffiliateRepository $affiliateRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->affiliateRepository = self::getContainer()->get(AffiliateRepository::class);
    }

    public function testGetById(): void
    {
        $affiliate = $this->affiliateRepository->get(AffiliateFixture::AFFILIATE_ACTIVE_ID);

        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_CODE, $affiliate->code);
    }

    public function testGetByIdThrowsForInvalidId(): void
    {
        $this->expectException(AffiliateNotFound::class);
        $this->affiliateRepository->get('00000000-0000-0000-0000-000000000099');
    }

    public function testGetByCodeIsCaseInsensitive(): void
    {
        $affiliate = $this->affiliateRepository->getByCode(strtolower(AffiliateFixture::AFFILIATE_ACTIVE_CODE));

        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $affiliate->id->toString());
    }

    public function testGetByCodeThrowsForNonexistentCode(): void
    {
        $this->expectException(AffiliateNotFound::class);
        $this->affiliateRepository->getByCode('NONEXISTENT');
    }

    public function testGetByPlayerId(): void
    {
        $affiliate = $this->affiliateRepository->getByPlayerId(PlayerFixture::PLAYER_REGULAR);

        self::assertSame(AffiliateFixture::AFFILIATE_ACTIVE_ID, $affiliate->id->toString());
    }

    public function testGetByPlayerIdThrowsForNonAffiliate(): void
    {
        $this->expectException(AffiliateNotFound::class);
        $this->affiliateRepository->getByPlayerId(PlayerFixture::PLAYER_ADMIN);
    }
}
