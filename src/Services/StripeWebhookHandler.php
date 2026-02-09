<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Message\NotifyAboutFailedPayment;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use Stripe\Invoice;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Component\Messenger\MessageBusInterface;

readonly final class StripeWebhookHandler
{
    public function __construct(
        private string $stripeWebhookSecret,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function handleWebhook(string $body, string $signHeader): void
    {
        $event = Webhook::constructEvent($body, $signHeader, $this->stripeWebhookSecret);

        switch ($event->type) {
            case 'customer.subscription.created':
                $subscription = $event->data->object ?? null;

                if ($subscription instanceof Subscription) {
                    $this->handleSubscriptionCreated($subscription);
                }
                break;

            case 'customer.subscription.updated':
                $subscription = $event->data->object ?? null;

                if ($subscription instanceof Subscription) {
                    $this->handleSubscriptionUpdated($subscription);
                }
                break;

            case 'customer.subscription.deleted':
                $subscription = $event->data->object ?? null;

                if ($subscription instanceof Subscription) {
                    $this->handleSubscriptionDeleted($subscription);
                }
                break;

            case 'invoice.payment_succeeded':
                $invoice = $event->data->object ?? null;

                if ($invoice instanceof Invoice) {
                    // In Stripe API v2025+, subscription moved to parent->subscription_details->subscription
                    $subscriptionId = $invoice->parent?->subscription_details->subscription ?? null;

                    if (is_string($subscriptionId)) {
                        $this->handlePaymentSucceeded($subscriptionId);
                    }
                }

                break;

            case 'invoice.payment_failed':
                $invoice = $event->data->object ?? null;

                if ($invoice instanceof Invoice) {
                    $this->handlePaymentFailed($invoice);
                }
                break;

            default:
                $this->logger->error('Unsupported Stripe webhook event', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                ]);
        }
    }

    private function handleSubscriptionCreated(Subscription $stripeSubscription): void
    {
        $this->messageBus->dispatch(new UpdateMembershipSubscription($stripeSubscription->id));
    }

    private function handleSubscriptionUpdated(Subscription $stripeSubscription): void
    {
        $this->messageBus->dispatch(new UpdateMembershipSubscription($stripeSubscription->id));
    }

    private function handlePaymentSucceeded(string $stripeSubscriptionId): void
    {
        $this->messageBus->dispatch(new UpdateMembershipSubscription($stripeSubscriptionId, isPaymentConfirmed: true));
    }

    private function handleSubscriptionDeleted(Subscription $stripeSubscription): void
    {
        $this->messageBus->dispatch(new CancelMembershipSubscription($stripeSubscription->id));
    }

    private function handlePaymentFailed(Invoice $invoice): void
    {
        if ($invoice->attempt_count === 1) {
            // In Stripe API v2025+, subscription moved to parent->subscription_details->subscription
            $stripeSubscriptionId = $invoice->parent?->subscription_details->subscription ?? null;

            if (is_string($stripeSubscriptionId)) {
                $this->messageBus->dispatch(new NotifyAboutFailedPayment($stripeSubscriptionId));
            }
        }
    }
}
