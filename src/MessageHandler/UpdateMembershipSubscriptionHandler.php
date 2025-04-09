<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class UpdateMembershipSubscriptionHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private MembershipRepository $membershipRepository,
        private LoggerInterface $logger,
        private LockFactory $lockFactory,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(UpdateMembershipSubscription $message): void
    {
        $lock = $this->lockFactory->createLock('stripe-subscription-' . $message->stripeSubscriptionId);
        $lock->acquire(blocking: true);

        $subscriptionId = $message->stripeSubscriptionId;
        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $subscriptionStatus = $subscription->status;
        $now = $this->clock->now();

        $customerId = $subscription->customer;
        assert(is_string($customerId));

        $customer = $this->stripeClient->customers->retrieve($customerId);
        $playerId = $customer->metadata->player_id ?? null;

        if (!is_string($playerId)) {
            $this->logger->critical('Stripe subscription missing playerId', [
                'stripe_subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $billingPeriodEnd = DateTimeImmutable::createFromFormat('U', (string) $subscription->current_period_end);
        assert($billingPeriodEnd instanceof DateTimeImmutable);

        try {
            try {
                // First try to search the stripe subscription membership
                $membership = $this->membershipRepository->getByStripeSubscriptionId($subscriptionId);
            } catch (MembershipNotFound) {
                // Then try to find by player - there can be free membership without stripe
                $membership = $this->membershipRepository->getByPlayerId($playerId);
            }

            if ($subscription->cancel_at_period_end === true) {
                $membership->cancel($billingPeriodEnd);
            } else {
                $membership->updateStripeSubscription($subscriptionId, $billingPeriodEnd, $subscriptionStatus, $now);
            }
        } catch (MembershipNotFound) {
            if (
                $subscriptionStatus === Subscription::STATUS_ACTIVE ||
                $subscriptionStatus === Subscription::STATUS_TRIALING
            ) {
                $player = $this->playerRepository->get($playerId);

                $membership = new Membership(
                    Uuid::uuid7(),
                    $player,
                    $now,
                    $subscriptionId,
                    $billingPeriodEnd,
                );

                $this->membershipRepository->save($membership);
            }
        }

        $lock->release();
    }
}
