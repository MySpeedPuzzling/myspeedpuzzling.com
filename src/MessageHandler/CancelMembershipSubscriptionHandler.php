<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CancelMembershipSubscriptionHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws MembershipNotFound
     */
    public function __invoke(CancelMembershipSubscription $message): void
    {
        $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);

        $membership->cancel($this->clock->now());
    }
}
