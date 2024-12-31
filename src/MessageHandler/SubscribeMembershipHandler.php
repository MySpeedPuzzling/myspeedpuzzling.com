<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\SubscribeMembership;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class SubscribeMembershipHandler
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private StripeClient $stripeClient,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SubscribeMembership $message): void
    {
        $player = $this->playerRepository->get($message->playerId);

        $checkoutSession = $this->stripeClient->checkout->sessions->retrieve($message->stripeSessionId);
        $subscriptionId = $checkoutSession->subscription;
        assert(is_string($subscriptionId));

        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $billingPeriodEnd = DateTimeImmutable::createFromFormat('U', (string) $subscription->current_period_end);
        assert($billingPeriodEnd instanceof DateTimeImmutable);

        try {
            $membership = $this->membershipRepository->get($message->playerId);
            $membership->updateStripeSubscription($subscriptionId, $billingPeriodEnd);
        } catch (MembershipNotFound) {
            $membership = new Membership(
                Uuid::uuid7(),
                $player,
                $this->clock->now(),
                $subscriptionId,
                $billingPeriodEnd,
            );

            $this->membershipRepository->save($membership);
        }
    }
}
