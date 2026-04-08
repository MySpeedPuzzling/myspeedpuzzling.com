<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\MarkPayoutPaid;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class MarkPayoutPaidHandler
{
    public function __construct(
        private AffiliatePayoutRepository $affiliatePayoutRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(MarkPayoutPaid $message): void
    {
        $payout = $this->affiliatePayoutRepository->get($message->payoutId);
        $payout->markAsPaid($this->clock->now());
    }
}
