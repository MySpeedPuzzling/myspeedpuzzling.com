<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnAllSubscriptions;
use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnSubscription;
use SpeedPuzzling\Web\Services\Membership\SubscriptionPaymentMethodSaving;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class EnableSavingPaymentMethodOnAllSubscriptionsHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @return list<string> the subscriptions that needed it (dispatched unless a dry run)
     */
    public function __invoke(EnableSavingPaymentMethodOnAllSubscriptions $message): array
    {
        $needing = [];

        // status=all, filtered here: the list endpoint takes one status at a time
        foreach ($this->stripeClient->subscriptions->all(['status' => 'all', 'limit' => 100])->autoPagingIterator() as $subscription) {
            if (SubscriptionPaymentMethodSaving::needsEnabling($subscription)) {
                $needing[] = $subscription->id;
            }
        }

        if ($message->dryRun === false) {
            foreach ($needing as $subscriptionId) {
                $this->messageBus->dispatch(new EnableSavingPaymentMethodOnSubscription($subscriptionId));
            }
        }

        return $needing;
    }
}
