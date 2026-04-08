<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\SuspendAffiliate;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Value\AffiliateStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class SuspendAffiliateHandlerTest extends KernelTestCase
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

    public function testSuspendActiveAffiliate(): void
    {
        $affiliate = $this->affiliateRepository->get(AffiliateFixture::AFFILIATE_ACTIVE_ID);
        self::assertSame(AffiliateStatus::Active, $affiliate->status);

        $this->messageBus->dispatch(new SuspendAffiliate(AffiliateFixture::AFFILIATE_ACTIVE_ID));

        $affiliate = $this->affiliateRepository->get(AffiliateFixture::AFFILIATE_ACTIVE_ID);
        self::assertSame(AffiliateStatus::Suspended, $affiliate->status);
    }
}
