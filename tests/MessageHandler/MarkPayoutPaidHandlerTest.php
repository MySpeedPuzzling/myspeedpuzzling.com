<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Message\MarkPayoutPaid;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\AffiliateFixture;
use SpeedPuzzling\Web\Value\PayoutStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MarkPayoutPaidHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private AffiliatePayoutRepository $payoutRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->payoutRepository = $container->get(AffiliatePayoutRepository::class);
    }

    public function testMarkPendingPayoutAsPaid(): void
    {
        $payout = $this->payoutRepository->get(AffiliateFixture::PAYOUT_PENDING_ID);
        self::assertSame(PayoutStatus::Pending, $payout->status);
        self::assertNull($payout->paidAt);

        $this->messageBus->dispatch(new MarkPayoutPaid(AffiliateFixture::PAYOUT_PENDING_ID));

        $payout = $this->payoutRepository->get(AffiliateFixture::PAYOUT_PENDING_ID);
        self::assertSame(PayoutStatus::Paid, $payout->status);
        self::assertNotNull($payout->paidAt);
    }
}
