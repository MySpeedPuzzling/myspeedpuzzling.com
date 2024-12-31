<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Events\SubscriptionPaymentFailed;
use Stripe\Invoice;
use Stripe\StripeClient;
use Stripe\Subscription;
use Stripe\Webhook;
use Symfony\Component\Messenger\MessageBusInterface;

readonly final class StripeWebhookHandler
{
    public function __construct(
        private string $stripeWebhookSecret,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
        private StripeClient $stripeClient,
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

            case 'customer.subscription.deleted':
                $subscription = $event->data->object ?? null;

                if ($subscription instanceof Subscription) {
                    $this->handleSubscriptionDeleted($subscription);
                }
                break;

            case 'invoice.payment_succeeded':
                $subscriptionId = $event->data->object->subscription ?? null;

                if (is_string($subscriptionId)) {
                    $this->handlePaymentSucceeded($subscriptionId);
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
    }

    private function handleSubscriptionDeleted(Subscription $stripeSubscription): void
    {
    }

    private function handlePaymentSucceeded(string $subscriptionId): void
    {
        $subscriptionId = $invoice->subscription;
        assert(is_string($subscriptionId));

        $this->messageBus->dispatch(new SubscriptionPaymentFailed($subscriptionId));
    }

    private function handlePaymentFailed(Invoice $invoice): void
    {
        if ($invoice->attempt_count === 1) {
            $subscriptionId = $invoice->subscription;
            assert(is_string($subscriptionId));

            $this->messageBus->dispatch(new SubscriptionPaymentFailed($subscriptionId));
        }
    }
}
