<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;

readonly final class CancelMembershipSubscriptionHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
    ) {
    }

    /**
     * @throws MembershipNotFound
     */
    public function __invoke(CancelMembershipSubscription $message): void
    {
        $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);

        $membership->cancel();
    }
}
