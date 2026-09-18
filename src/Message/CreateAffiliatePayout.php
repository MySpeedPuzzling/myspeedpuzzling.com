<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * Dispatched for the same invoice by the Stripe webhook and the checkout-success page,
 * often at the same moment - the lock keeps the handler's "already exists?" check honest.
 */
readonly final class CreateAffiliatePayout implements SerializedByLock
{
    public function __construct(
        public string $stripeSubscriptionId,
        public string $stripeInvoiceId,
    ) {
    }

    public function lockKey(): string
    {
        return 'affiliate-payout-' . $this->stripeInvoiceId;
    }
}
