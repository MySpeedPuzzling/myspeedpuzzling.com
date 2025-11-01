<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class CancelMembershipSubscriptionHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CancelMembershipSubscription $message): void
    {
        $lock = $this->lockFactory->createLock('stripe-subscription-' . $message->stripeSubscriptionId);
        $lock->acquire(blocking: true);

        try {
            $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);
        } catch (MembershipNotFound) {
            $this->logger->info('Subscription deletion webhook received for non-existent membership', [
                'subscription_id' => $message->stripeSubscriptionId,
            ]);

            $lock->release();
            return;
        }

        // Validate that this subscription is the current one for this membership
        if ($membership->stripeSubscriptionId !== $message->stripeSubscriptionId) {
            $this->logger->warning('Subscription deletion webhook received for membership with different subscription', [
                'webhook_subscription_id' => $message->stripeSubscriptionId,
                'current_subscription_id' => $membership->stripeSubscriptionId,
                'membership_id' => $membership->id->toString(),
            ]);

            $lock->release();
            return;
        }

        $membership->cancel($this->clock->now());

        $lock->release();
    }
}
