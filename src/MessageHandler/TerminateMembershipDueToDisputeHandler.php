<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\TerminateMembershipDueToDispute;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * A lost dispute means the money is gone for good - the bank pulled it back and we can not contest it
 * anymore. The player must lose access immediately, and the subscription has to go too, otherwise Stripe
 * keeps charging the very payment method that just bounced and we collect another dispute fee every cycle.
 *
 * Only `charge.dispute.closed` gets us here. SEPA direct debit returns (insufficient funds, account
 * closed, ...) are lost the moment they are created, so Stripe fires `created` and `closed` in the same
 * breath - acting on both would run this twice for one dispute, and the two deliveries race each other
 * closely enough that the lock alone would not save us (it is released before the surrounding Doctrine
 * transaction commits, so the loser could still read a membership that looks untouched).
 */
#[AsMessageHandler]
readonly final class TerminateMembershipDueToDisputeHandler
{
    public function __construct(
        private StripeClient $stripeClient,
        private MembershipRepository $membershipRepository,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(TerminateMembershipDueToDispute $message): void
    {
        $dispute = $this->stripeClient->disputes->retrieve($message->stripeDisputeId);

        // A dispute we defended, or one the bank withdrew, costs us nothing - only a lost one does.
        if ($dispute->status !== Dispute::STATUS_LOST) {
            $this->logger->info('Stripe dispute closed without losing the money', [
                'stripe_dispute_id' => $message->stripeDisputeId,
                'dispute_status' => $dispute->status,
                'dispute_reason' => $dispute->reason,
            ]);

            return;
        }

        $subscriptionId = $this->resolveSubscriptionId($dispute);

        if ($subscriptionId === null) {
            $this->logger->warning('Lost Stripe dispute could not be traced to a subscription', [
                'stripe_dispute_id' => $message->stripeDisputeId,
            ]);

            return;
        }

        $lock = $this->lockFactory->createLock('stripe-subscription-' . $subscriptionId);
        $lock->acquire(blocking: true);

        try {
            $this->terminateMembership($subscriptionId, $dispute, $message->stripeDisputeId);

            // Runs even when no membership matches: the subscription is still ours and still pointed at a
            // payment method that just bounced, so leaving it alive only buys another dispute fee.
            $this->cancelStripeSubscription($subscriptionId);
        } finally {
            $lock->release();
        }
    }

    private function terminateMembership(string $subscriptionId, Dispute $dispute, string $disputeId): void
    {
        try {
            $membership = $this->membershipRepository->getByStripeSubscriptionId($subscriptionId);
        } catch (MembershipNotFound) {
            $this->logger->warning('Lost Stripe dispute for subscription without membership', [
                'stripe_dispute_id' => $disputeId,
                'stripe_subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $now = $this->clock->now();

        // A membership cancelled at period end still has a future `endsAt` and is still fully active -
        // the player paid for those remaining days with the money that just went back to their bank.
        if ($membership->endsAt !== null && $membership->endsAt <= $now) {
            return;
        }

        $membership->cancel($now);

        $this->logger->warning('Membership terminated because a Stripe dispute was lost', [
            'stripe_dispute_id' => $disputeId,
            'stripe_subscription_id' => $subscriptionId,
            'membership_id' => $membership->id->toString(),
            'player_id' => $membership->player->id->toString(),
            'dispute_reason' => $dispute->reason,
        ]);
    }

    /**
     * Dispute -> charge -> invoice -> subscription. Disputes carry no subscription of their own, and a
     * disputed charge does not have to belong to a subscription at all (one-off payments never will).
     */
    private function resolveSubscriptionId(Dispute $dispute): null|string
    {
        $disputedCharge = $dispute->charge;
        $chargeId = $disputedCharge instanceof Charge ? $disputedCharge->id : $disputedCharge;

        $charge = $this->stripeClient->charges->retrieve($chargeId);

        // `invoice` is still returned by the API but no longer part of the generated Charge docblock.
        $invoiceId = $charge->offsetGet('invoice');

        if (!is_string($invoiceId)) {
            return null;
        }

        $invoice = $this->stripeClient->invoices->retrieve($invoiceId);
        $subscription = $invoice->parent?->subscription_details->subscription ?? null;

        if ($subscription instanceof Subscription) {
            return $subscription->id;
        }

        return is_string($subscription) ? $subscription : null;
    }

    private function cancelStripeSubscription(string $subscriptionId): void
    {
        try {
            $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);

            if ($subscription->status === Subscription::STATUS_CANCELED) {
                return;
            }

            $this->stripeClient->subscriptions->cancel($subscriptionId);
        } catch (ApiErrorException $e) {
            // The membership is already terminated locally - losing the Stripe side only means the dead
            // payment method gets charged again, which is worth an alert but not a failed message.
            $this->logger->error('Could not cancel Stripe subscription after a lost dispute', [
                'stripe_subscription_id' => $subscriptionId,
                'exception' => $e,
            ]);
        }
    }
}
