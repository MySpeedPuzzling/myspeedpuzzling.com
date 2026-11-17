<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Membership;

use Stripe\Subscription;

final class SubscriptionPaymentMethodSaving
{
    public const string ON_SUBSCRIPTION = 'on_subscription';

    /**
     * Living subscriptions only - a canceled or expired one is never charged again (and Stripe refuses its update)
     */
    private const array LIVING_STATUSES = [
        Subscription::STATUS_ACTIVE,
        Subscription::STATUS_TRIALING,
        Subscription::STATUS_PAST_DUE,
        Subscription::STATUS_UNPAID,
        Subscription::STATUS_PAUSED,
    ];

    public static function needsEnabling(Subscription $subscription): bool
    {
        if (!in_array($subscription->status, self::LIVING_STATUSES, true)) {
            return false;
        }

        $current = $subscription->payment_settings->save_default_payment_method ?? null;

        return $current !== self::ON_SUBSCRIPTION;
    }
}
