<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Stripe\Price;
use Stripe\Subscription;

/**
 * The one rule on the live Stripe subscription: may this customer switch between the regular monthly and
 * yearly plan right now (docs/features/stripe.md "Switching plans"). Asked right before a portal session is
 * created, so a stale copy in our database can never open the switch for a subscription that changed.
 */
final class MembershipPlanSwitchEligibility
{
    public static function targetFor(Subscription $subscription, string $expectedCustomerId): null|MembershipPlan
    {
        $customer = $subscription->customer;
        $customerId = is_string($customer) ? $customer : ($customer->id ?? null);

        if ($customerId !== $expectedCustomerId) {
            return null;
        }

        // Not trialing: prorating trial days would end the trial and charge at once
        if ($subscription->status !== Subscription::STATUS_ACTIVE) {
            return null;
        }

        if (
            ($subscription->cancel_at_period_end ?? false) === true
            || ($subscription->cancel_at ?? null) !== null
            // A voucher's free months pause collection
            || ($subscription->pause_collection ?? null) !== null
            // A change already booked (or anything else a schedule plans)
            || ($subscription->schedule ?? null) !== null
            // A payment of an earlier update still waiting
            || ($subscription->pending_update ?? null) !== null
        ) {
            return null;
        }

        $items = $subscription->items->data;

        if (count($items) !== 1) {
            return null;
        }

        $item = $items[0];

        if (($item->quantity ?? null) !== 1) {
            return null;
        }

        $price = $item->price ?? null;

        if (!$price instanceof Price) {
            return null;
        }

        return MembershipPlan::fromLookupKey($price->lookup_key)?->switchTarget();
    }
}
