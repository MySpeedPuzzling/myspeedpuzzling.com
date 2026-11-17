<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Membership;

use SpeedPuzzling\Web\Exceptions\MembershipPaymentPending;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;

/**
 * Asked before a new checkout when our membership row looks ended but still names a Stripe subscription.
 * A failed renewal pauses the membership at once (ends_at = now, docs/features/stripe.md state 5) while Stripe
 * keeps the subscription and retries its invoice - a new checkout then started a second subscription at today's
 * price next to the old one (a 100 CZK early adopter who changed cards was offered 150 CZK, 2026-11-17).
 */
readonly final class LiveSubscriptionGuard
{
    public function __construct(
        private StripeClient $stripeClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws PlayerAlreadyHaveMembership the subscription is running - manage it in the billing portal
     * @throws MembershipPaymentPending its open invoice waits for a payment - pay that one
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

        switch ($subscription->status) {
            case Subscription::STATUS_ACTIVE:
            case Subscription::STATUS_TRIALING:
                throw new PlayerAlreadyHaveMembership();

            case Subscription::STATUS_PAST_DUE:
            case Subscription::STATUS_UNPAID:
            case Subscription::STATUS_INCOMPLETE:
                $invoice = $subscription->latest_invoice;

                if (
                    $invoice instanceof Invoice
                    && $invoice->status === Invoice::STATUS_OPEN
                    && is_string($invoice->hosted_invoice_url)
                ) {
                    throw new MembershipPaymentPending($invoice->hosted_invoice_url);
                }

                // Nothing to pay here, yet the subscription lives - the billing portal shows what it needs
                throw new PlayerAlreadyHaveMembership();

            default:
                // canceled, incomplete_expired: gone for good. paused (a checkout trial ended without a card):
                // never charges, so a new subscription cannot double-bill
                return;
        }
    }
}
