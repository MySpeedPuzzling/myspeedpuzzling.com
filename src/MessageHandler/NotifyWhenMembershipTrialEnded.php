<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Events\MembershipSubscriptionRenewed;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class NotifyWhenMembershipTrialEnded
{
    public function __construct(
        private MembershipRepository $membershipRepository,
    ) {
    }

    public function __invoke(MembershipSubscriptionRenewed $event): void
    {
        $this->membershipRepository->get($event->membershipId->toString());
    }
}
