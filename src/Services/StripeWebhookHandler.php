<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Stripe\Subscription;

readonly final class StripeWebhookHandler
{
    public function handleTrialWillEnd(Subscription $stripeSubscription): void
    {
    }

    public function handleSubscriptionCreated(Subscription $stripeSubscription): void
    {
    }

    public function handleSubscriptionDeleted(Subscription $stripeSubscription): void
    {
    }

    public function handleSubscriptionUpdated(Subscription $stripeSubscription): void
    {
    }
}
