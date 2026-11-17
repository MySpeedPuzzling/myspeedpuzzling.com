<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnSubscription;
use SpeedPuzzling\Web\Services\Membership\SubscriptionPaymentMethodSaving;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class EnableSavingPaymentMethodOnSubscriptionHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ApiErrorException the transport tries again
     */
    public function __invoke(EnableSavingPaymentMethodOnSubscription $message): void
    {
        $subscription = $this->stripeClient->subscriptions->retrieve($message->stripeSubscriptionId);

        if (!SubscriptionPaymentMethodSaving::needsEnabling($subscription)) {
            return;
        }

        // Only this one setting - price, items, billing date and the cards themselves stay as they are
        $this->stripeClient->subscriptions->update($message->stripeSubscriptionId, [
            'payment_settings' => [
                'save_default_payment_method' => SubscriptionPaymentMethodSaving::ON_SUBSCRIPTION,
            ],
        ]);

        $this->logger->info('Stripe now saves the paying card as the subscription default', [
            'stripe_subscription_id' => $message->stripeSubscriptionId,
        ]);
    }
}
