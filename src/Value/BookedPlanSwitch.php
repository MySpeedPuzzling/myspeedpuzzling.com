<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Stripe\Price;
use Stripe\StripeObject;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

/**
 * The one rule for undoing a booked switch (docs/features/stripe.md "Switching plans"): the schedule must be
 * exactly what Stripe's portal books for our plan switch - this customer's, still running, ending by `release`,
 * two phases: the current regular plan until the period end, then the other regular plan. Only then may it be
 * released, which keeps the subscription as it is. Anything else (a schedule made by hand in the Dashboard, more
 * phases, other prices) is left alone - never cancelled: cancelling a schedule cancels its subscription.
 */
final class BookedPlanSwitch
{
    /**
     * @return null|MembershipPlan the plan the member keeps, null = not a releasable booked switch
     */
    public static function keptPlan(Subscription $subscription, SubscriptionSchedule $schedule, string $expectedCustomerId, int $now): null|MembershipPlan
    {
        $customer = $subscription->customer;
        $customerId = is_string($customer) ? $customer : ($customer->id ?? null);

        if (
            $customerId !== $expectedCustomerId
            || $subscription->status !== Subscription::STATUS_ACTIVE
            || ($subscription->cancel_at_period_end ?? false) === true
            || ($subscription->cancel_at ?? null) !== null
            || ($subscription->pause_collection ?? null) !== null
        ) {
            return null;
        }

        $subscriptionSchedule = $subscription->schedule ?? null;
        $subscriptionScheduleId = $subscriptionSchedule instanceof SubscriptionSchedule ? $subscriptionSchedule->id : $subscriptionSchedule;
        $scheduleSubscription = $schedule->subscription ?? null;
        $scheduleSubscriptionId = $scheduleSubscription instanceof Subscription ? $scheduleSubscription->id : $scheduleSubscription;
        $scheduleCustomer = $schedule->customer ?? null;
        $scheduleCustomerId = is_string($scheduleCustomer) ? $scheduleCustomer : ($scheduleCustomer->id ?? null);

        if (
            $subscriptionScheduleId !== $schedule->id
            || $scheduleSubscriptionId !== $subscription->id
            || $scheduleCustomerId !== $expectedCustomerId
            || $schedule->status !== SubscriptionSchedule::STATUS_ACTIVE
            || $schedule->end_behavior !== 'release'
            || ($schedule->released_at ?? null) !== null
            || ($schedule->canceled_at ?? null) !== null
        ) {
            return null;
        }

        $items = $subscription->items->data;

        if (count($items) !== 1) {
            return null;
        }

        $current = MembershipPlan::fromLookupKey(($items[0]->price ?? null)?->lookup_key);

        if ($current === null) {
            return null;
        }

        $phases = $schedule->phases;

        if (count($phases) !== 2) {
            return null;
        }

        [$running, $booked] = $phases;

        if (
            self::phasePlan($running) !== $current
            || self::phasePlan($booked) !== $current->switchTarget()
            || $running->start_date > $now
            || $running->end_date <= $now
            || $booked->start_date !== $running->end_date
        ) {
            return null;
        }

        return $current;
    }

    private static function phasePlan(StripeObject $phase): null|MembershipPlan
    {
        $items = $phase->items ?? [];

        if (!is_array($items) || count($items) !== 1) {
            return null;
        }

        $item = $items[0];

        if (!$item instanceof StripeObject || ($item->quantity ?? null) !== 1) {
            return null;
        }

        $price = $item->price ?? null;

        return $price instanceof Price ? MembershipPlan::fromLookupKey($price->lookup_key) : null;
    }
}
