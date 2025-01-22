<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Message\CreateMembershipSubscription;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Stripe\StripeClient;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
readonly final class CreateMembershipSubscriptionHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
        private MessageBusInterface $messageBus,
    ) {
    }

    /**
     * @throws PlayerNotFound
     */
    public function __invoke(CreateMembershipSubscription $message): void
    {
        $subscriptionId = $message->stripeSubscriptionId;
        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $billingPeriodEnd = DateTimeImmutable::createFromFormat('U', (string) $subscription->current_period_end);
        assert($billingPeriodEnd instanceof DateTimeImmutable);

        $customerId = $subscription->customer;
        assert(is_string($customerId));

        $customer = $this->stripeClient->customers->retrieve($customerId);
        $playerId = $customer->metadata->player_id ?? null;

        if (!is_string($playerId)) {
            // Can not continue without playerId
            return;
        }

        try {
            $membership = $this->membershipRepository->getByPlayerId($playerId);
            $this->messageBus->dispatch(
                new UpdateMembershipSubscription($subscriptionId, $membership->id->toString()),
            );
        } catch (MembershipNotFound) {
            $player = $this->playerRepository->get($playerId);
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
