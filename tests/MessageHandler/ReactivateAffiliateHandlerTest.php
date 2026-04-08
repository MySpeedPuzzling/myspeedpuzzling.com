<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\ReactivateAffiliate;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ReactivateAffiliateHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private AffiliateRepository $affiliateRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->affiliateRepository = $container->get(AffiliateRepository::class);
    }

    public function testReactivateSuspendedAffiliate(): void
    {
        $affiliate = $this->affiliateRepository->get(AffiliateFixture::AFFILIATE_SUSPENDED_ID);
        self::assertSame(AffiliateStatus::Suspended, $affiliate->status);

        $this->messageBus->dispatch(new ReactivateAffiliate(AffiliateFixture::AFFILIATE_SUSPENDED_ID));

        $affiliate = $this->affiliateRepository->get(AffiliateFixture::AFFILIATE_SUSPENDED_ID);
        self::assertSame(AffiliateStatus::Active, $affiliate->status);
    }
}
