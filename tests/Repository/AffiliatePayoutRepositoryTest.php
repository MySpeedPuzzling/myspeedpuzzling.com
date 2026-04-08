<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Repository;

use SpeedPuzzling\Web\Exceptions\AffiliatePayoutNotFound;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AffiliatePayoutRepositoryTest extends KernelTestCase
{
    private AffiliatePayoutRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->repository = self::getContainer()->get(AffiliatePayoutRepository::class);
    }

    public function testGetById(): void
    {
        $payout = $this->repository->get(AffiliateFixture::PAYOUT_PENDING_ID);

        self::assertSame('in_test_pending_001', $payout->stripeInvoiceId);
    }

    public function testGetByIdThrowsForInvalidId(): void
    {
        $this->expectException(AffiliatePayoutNotFound::class);
        $this->repository->get('00000000-0000-0000-0000-000000000099');
    }

    public function testExistsByStripeInvoiceIdReturnsTrue(): void
    {
        self::assertTrue($this->repository->existsByStripeInvoiceId('in_test_pending_001'));
    }

    public function testExistsByStripeInvoiceIdReturnsFalse(): void
    {
        self::assertFalse($this->repository->existsByStripeInvoiceId('in_nonexistent'));
    }
}
