<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\SuspendAffiliate;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SuspendAffiliateHandler
{
    public function __construct(
        private AffiliateRepository $affiliateRepository,
    ) {
    }

    public function __invoke(SuspendAffiliate $message): void
    {
        $affiliate = $this->affiliateRepository->get($message->affiliateId);
        $affiliate->suspend();
    }
}
