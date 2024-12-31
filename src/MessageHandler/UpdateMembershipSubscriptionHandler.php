<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdateMembershipSubscriptionHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private MembershipRepository $membershipRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(UpdateMembershipSubscription $message): void
    {
        $subscriptionId = $message->stripeSubscriptionId;

        try {
            $membership = $this->membershipRepository->getByStripeSubscriptionId($subscriptionId);
        } catch (MembershipNotFound) {
            $this->logger->warning('Attempted to update unknown membership', [
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $billingPeriodEnd = DateTimeImmutable::createFromFormat('U', (string) $subscription->current_period_end);
        assert($billingPeriodEnd instanceof DateTimeImmutable);

        $membership->updateStripeSubscription($subscriptionId, $billingPeriodEnd);
    }
}
