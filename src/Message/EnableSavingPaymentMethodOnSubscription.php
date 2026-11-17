<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Turns on Stripe's `payment_settings.save_default_payment_method = on_subscription` for one subscription: the card
 * that pays a subscription invoice - also a new card on the hosted invoice page during the retry period - becomes the
 * subscription's default, so the next renewal charges it (docs/features/stripe.md "Cards"). Stripe manages the cards,
 * we only switch its behaviour on. Checkout cannot set it, hence after customer.subscription.created. Only a Stripe
 * call, nothing written here - outside a transaction.
 */
readonly final class EnableSavingPaymentMethodOnSubscription implements HandledOutsideTransaction
{
    public function __construct(
        public string $stripeSubscriptionId,
    ) {
    }
}
