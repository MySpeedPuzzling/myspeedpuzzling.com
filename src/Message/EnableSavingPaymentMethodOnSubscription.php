<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Turns on Stripe's `payment_settings.save_default_payment_method = on_subscription` for a subscription whose payment
 * just failed: the card the player then pays the open invoice with becomes the subscription's default, so the next
 * renewal charges it instead of the old card (a Checkout subscription's own default card wins over the customer's).
 * Stripe manages the cards, we only switch its behaviour on (docs/features/stripe.md "Cards"). Only a Stripe call,
 * nothing written here - outside a transaction.
 */
readonly final class EnableSavingPaymentMethodOnSubscription implements HandledOutsideTransaction
{
    public function __construct(
        public string $stripeSubscriptionId,
    ) {
    }
}
