<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\ApproveAffiliate;
use SpeedPuzzling\Web\Repository\AffiliateRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ApproveAffiliateHandler
{
    public function __construct(
        private AffiliateRepository $affiliateRepository,
    ) {
    }

    public function __invoke(ApproveAffiliate $message): void
    {
        $affiliate = $this->affiliateRepository->get($message->affiliateId);
        $affiliate->approve();
    }
}
