<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use SpeedPuzzling\Web\Message\CreateMembershipSubscription;
use SpeedPuzzling\Web\Message\SubscribeMembership;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class SubscribeMembershipHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function __invoke(SubscribeMembership $message): void
    {
        $checkoutSession = $this->stripeClient->checkout->sessions->retrieve($message->stripeSessionId);
        $subscriptionId = $checkoutSession->subscription;
        assert(is_string($subscriptionId));

        $this->messageBus->dispatch(
            new CreateMembershipSubscription($subscriptionId),
        );
    }
}
