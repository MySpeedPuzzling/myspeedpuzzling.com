<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Membership;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipPaymentPending;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Asked before a new checkout when our membership row looks ended but still names a Stripe subscription.
 * A failed renewal pauses the membership at once (ends_at = now, docs/features/stripe.md state 5) while Stripe
 * keeps the subscription and retries its invoice - a new checkout then started a second subscription at today's
 * price next to the old one (a 100 CZK early adopter who changed cards was offered 150 CZK, 2026-11-17).
 *
 * Narrow on purpose: only the retry period of the current billing period leads to the open invoice. A player who
 * comes back after the period is over (half a year later) gets a new checkout as before - never an old invoice for
 * months they did not use.
 */
readonly final class LiveSubscriptionGuard
{
    public function __construct(
        private StripeClient $stripeClient,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerAlreadyHaveMembership the subscription is running - manage it in the billing portal
     * @throws MembershipPaymentPending Stripe retries this billing period's invoice - pay that one
     */
    public function assertNoLiveSubscription(string $stripeSubscriptionId): void
    {
        try {
            $subscription = $this->stripeClient->subscriptions->retrieve($stripeSubscriptionId, [
                'expand' => ['latest_invoice'],
            ]);
        } catch (ApiErrorException $e) {
            // Fail open: buying must keep working exactly as before this guard when Stripe cannot answer
            $this->logger->warning('Could not check the existing Stripe subscription before a new checkout', [
                'stripe_subscription_id' => $stripeSubscriptionId,
                'exception' => $e,
            ]);

            return;
        }

        if (
            $subscription->status === Subscription::STATUS_ACTIVE
            || $subscription->status === Subscription::STATUS_TRIALING
        ) {
            throw new PlayerAlreadyHaveMembership();
        }

        if ($subscription->status !== Subscription::STATUS_PAST_DUE) {
            // canceled, incomplete_expired, unpaid (retries over), incomplete, paused: a new checkout as before
            return;
        }

        // In Stripe API v2025+, current_period_end moved from Subscription to SubscriptionItem
        $periodEnd = $subscription->items->data[0]->current_period_end ?? null;

        if (!is_int($periodEnd) || $periodEnd <= $this->clock->now()->getTimestamp()) {
            // The period the open invoice is for is over - a new checkout as before
            return;
        }

        $invoice = $subscription->latest_invoice;

        if (
            $invoice instanceof Invoice
            && $invoice->status === Invoice::STATUS_OPEN
            && is_string($invoice->hosted_invoice_url)
        ) {
            throw new MembershipPaymentPending($invoice->hosted_invoice_url);
        }
    }
}
