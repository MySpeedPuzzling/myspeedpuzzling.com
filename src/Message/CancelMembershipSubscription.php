<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

readonly final class CancelMembershipSubscription implements SerializedByLock
{
    public function __construct(
        public string $stripeSubscriptionId,
    ) {
    }

    /**
     * Shared with UpdateMembershipSubscription and TerminateMembershipDueToDisputeHandler,
     * so nothing else touches the subscription's membership at the same time.
     */
    public function lockKey(): string
    {
        return 'stripe-subscription-' . $this->stripeSubscriptionId;
    }
}
