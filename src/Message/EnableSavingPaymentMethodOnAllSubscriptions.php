<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * The one-time backfill of EnableSavingPaymentMethodOnSubscription for subscriptions created before it existed:
 * lists the living subscriptions in Stripe and dispatches one message per subscription that still has it off.
 */
readonly final class EnableSavingPaymentMethodOnAllSubscriptions implements HandledOutsideTransaction
{
    public function __construct(
        public bool $dryRun,
    ) {
    }
}
