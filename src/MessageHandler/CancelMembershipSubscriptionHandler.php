<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\CancelMembershipSubscription;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Serialized per subscription by the message lock (CancelMembershipSubscription is SerializedByLock).
 */
#[AsMessageHandler]
readonly final class CancelMembershipSubscriptionHandler
{
    public function __construct(
        private MembershipRepository $membershipRepository,
        private PlayerRepository $playerRepository,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CancelMembershipSubscription $message): void
    {
        try {
            $membership = $this->membershipRepository->getByStripeSubscriptionId($message->stripeSubscriptionId);
        } catch (MembershipNotFound) {
            $this->logger->info('Subscription deletion webhook received for non-existent membership', [
                'subscription_id' => $message->stripeSubscriptionId,
            ]);

            return;
        }

        // Validate that this subscription is the current one for this membership
        if ($membership->stripeSubscriptionId !== $message->stripeSubscriptionId) {
            $this->logger->warning('Subscription deletion webhook received for membership with different subscription', [
                'webhook_subscription_id' => $message->stripeSubscriptionId,
                'current_subscription_id' => $membership->stripeSubscriptionId,
                'membership_id' => $membership->id->toString(),
            ]);

            return;
        }

        $membership->cancel($this->clock->now());

        // Clear any claimed discount voucher so future subscriptions start fresh
        $player = $this->playerRepository->get($membership->player->id->toString());
        if ($player->claimedDiscountVoucher !== null) {
            $player->clearClaimedDiscountVoucher();

            $this->logger->info('Cleared claimed discount voucher on subscription cancellation', [
                'player_id' => $player->id->toString(),
                'subscription_id' => $message->stripeSubscriptionId,
            ]);
        }
    }
}
