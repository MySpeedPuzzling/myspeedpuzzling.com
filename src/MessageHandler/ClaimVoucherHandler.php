<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Voucher;
use SpeedPuzzling\Web\Entity\VoucherClaim;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyClaimedVoucher;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHasLifetimeMembership;
use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Exceptions\VoucherUsageLimitReached;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\VoucherClaimRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
use SpeedPuzzling\Web\Results\ClaimVoucherResult;
use SpeedPuzzling\Web\Services\StripeCouponManager;
use SpeedPuzzling\Web\Value\LifetimeMembership;
use SpeedPuzzling\Web\Value\VoucherType;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
readonly final class ClaimVoucherHandler
{
    public function __construct(
        private VoucherRepository $voucherRepository,
        private PlayerRepository $playerRepository,
        private MembershipRepository $membershipRepository,
        private VoucherClaimRepository $voucherClaimRepository,
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        private StripeClient $stripeClient,
        private StripeCouponManager $stripeCouponManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws VoucherNotFound
     * @throws VoucherAlreadyUsed
     * @throws VoucherExpired
     * @throws VoucherUsageLimitReached
     * @throws PlayerAlreadyClaimedVoucher
     * @throws PlayerAlreadyHasLifetimeMembership
     */
    public function __invoke(ClaimVoucher $message): ClaimVoucherResult
    {
        $lock = $this->lockFactory->createLock('voucher-claim-' . strtoupper(trim($message->voucherCode)));
        $lock->acquire(blocking: true);

        try {
            $voucher = $this->voucherRepository->getByCode($message->voucherCode);
            $player = $this->playerRepository->get($message->playerId);
            $now = $this->clock->now();

            // Checked before anything else: re-entering a code you already redeemed is not a failure,
            // and "expired" or "already used" would wrongly suggest the benefit was lost
            if ($this->hasPlayerClaimed($voucher, $player)) {
                throw new PlayerAlreadyClaimedVoucher();
            }

            if ($voucher->isExpired($now)) {
                throw new VoucherExpired();
            }

            // Nothing a voucher gives can add to a membership that never ends - don't let the code go to waste
            if ($this->hasLifetimeMembership($player)) {
                throw new PlayerAlreadyHasLifetimeMembership();
            }

            return match ($voucher->voucherType) {
                VoucherType::FreeMonths => $this->handleFreeMonthsVoucher($voucher, $player, $now),
                VoucherType::PercentageDiscount => $this->handlePercentageVoucher($voucher, $player, $now),
                VoucherType::Lifetime => $this->handleLifetimeVoucher($voucher, $player, $now),
            };
        } finally {
            $lock->release();
        }
    }

    private function hasPlayerClaimed(Voucher $voucher, Player $player): bool
    {
        if ($voucher->voucherType === VoucherType::PercentageDiscount) {
            return $this->voucherClaimRepository->hasPlayerClaimedVoucher($player->id->toString(), $voucher->id->toString());
        }

        return $voucher->isUsedBy($player);
    }

    private function hasLifetimeMembership(Player $player): bool
    {
        try {
            return $this->membershipRepository->getByPlayerId($player->id->toString())->hasLifetimeGrant();
        } catch (MembershipNotFound) {
            return false;
        }
    }

    /**
     * @throws VoucherAlreadyUsed
     */
    private function handleLifetimeVoucher(
        Voucher $voucher,
        Player $player,
        \DateTimeImmutable $now,
    ): ClaimVoucherResult {
        if ($voucher->isUsed()) {
            throw new VoucherAlreadyUsed();
        }

        try {
            $membership = $this->membershipRepository->getByPlayerId($player->id->toString());

            if ($membership->stripeSubscriptionId !== null) {
                $this->stopSubscriptionBilling($membership->stripeSubscriptionId);
            }

            $membership->grantLifetime();
        } catch (MembershipNotFound) {
            $membership = new Membership(
                id: Uuid::uuid7(),
                player: $player,
                createdAt: $now,
                grantedUntil: LifetimeMembership::grantedUntil(),
            );

            $this->membershipRepository->save($membership);
        }

        $voucher->markAsUsed($player, $now);

        $this->logger->info('Lifetime voucher claimed successfully', [
            'voucher_id' => $voucher->id->toString(),
            'voucher_code' => $voucher->code,
            'player_id' => $player->id->toString(),
            'stripe_subscription_id' => $membership->stripeSubscriptionId,
        ]);

        return new ClaimVoucherResult(
            success: true,
            voucherType: VoucherType::Lifetime,
            redirectToMembership: false,
        );
    }

    /**
     * A lifetime member must never be charged again. A subscription in good standing runs out the period
     * that is already paid for; one that is behind on payment is cancelled right away, so Stripe stops
     * retrying the charge. The webhooks that follow only move `ends_at` - `granted_until` keeps access.
     */
    private function stopSubscriptionBilling(string $subscriptionId): void
    {
        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);

        if ($subscription->status === Subscription::STATUS_CANCELED || $subscription->status === Subscription::STATUS_INCOMPLETE_EXPIRED) {
            return;
        }

        if ($subscription->status === Subscription::STATUS_ACTIVE || $subscription->status === Subscription::STATUS_TRIALING) {
            if ($subscription->cancel_at_period_end !== true) {
                $this->stripeClient->subscriptions->update($subscriptionId, [
                    'cancel_at_period_end' => true,
                ]);
            }

            $cancellation = 'at_period_end';
        } else {
            $this->stripeClient->subscriptions->cancel($subscriptionId);
            $cancellation = 'immediately';
        }

        $this->logger->info('Stripe subscription cancelled for lifetime voucher', [
            'subscription_id' => $subscriptionId,
            'subscription_status' => $subscription->status,
            'cancellation' => $cancellation,
        ]);
    }

    /**
     * @throws VoucherAlreadyUsed
     */
    private function handleFreeMonthsVoucher(
        Voucher $voucher,
        Player $player,
        \DateTimeImmutable $now,
    ): ClaimVoucherResult {
        if ($voucher->isUsed()) {
            throw new VoucherAlreadyUsed();
        }

        assert($voucher->monthsValue !== null);
        $voucherEndDate = $now->add(new DateInterval('P' . $voucher->monthsValue . 'M'));

        try {
            $membership = $this->membershipRepository->getByPlayerId($player->id->toString());

            if ($membership->stripeSubscriptionId !== null && $membership->endsAt === null) {
                // Active Stripe subscription: extend billing via trial_end (works for both monthly and yearly)
                [$freePeriodStartsAt, $freePeriodEndsAt] = $this->applyFreeMonthsToSubscription($membership->stripeSubscriptionId, $voucher->monthsValue);
                $membership->billingPeriodEndsAt = $freePeriodEndsAt;
            } else {
                [$freePeriodStartsAt, $freePeriodEndsAt] = $this->extendMembership($membership, $now, $voucher->monthsValue);
            }
        } catch (MembershipNotFound) {
            $membership = new Membership(
                id: Uuid::uuid7(),
                player: $player,
                createdAt: $now,
                grantedUntil: $voucherEndDate,
            );

            $this->membershipRepository->save($membership);

            [$freePeriodStartsAt, $freePeriodEndsAt] = [$now, $voucherEndDate];
        }

        $voucher->markAsUsed($player, $now);
        $voucher->recordFreePeriod($freePeriodStartsAt, $freePeriodEndsAt);

        $this->logger->info('Free months voucher claimed successfully', [
            'voucher_id' => $voucher->id->toString(),
            'voucher_code' => $voucher->code,
            'player_id' => $player->id->toString(),
            'months_value' => $voucher->monthsValue,
        ]);

        return new ClaimVoucherResult(
            success: true,
            voucherType: VoucherType::FreeMonths,
            redirectToMembership: false,
            freeMonths: $voucher->monthsValue,
        );
    }

    /**
     * @throws VoucherUsageLimitReached
     */
    private function handlePercentageVoucher(
        Voucher $voucher,
        Player $player,
        \DateTimeImmutable $now,
    ): ClaimVoucherResult {
        $usageCount = $this->voucherClaimRepository->countClaimsForVoucher($voucher->id->toString());

        if (!$voucher->hasRemainingUses($usageCount)) {
            throw new VoucherUsageLimitReached();
        }

        $claim = new VoucherClaim(
            id: Uuid::uuid7(),
            voucher: $voucher,
            player: $player,
            claimedAt: $now,
        );

        $this->voucherClaimRepository->save($claim);

        try {
            $membership = $this->membershipRepository->getByPlayerId($player->id->toString());

            if ($membership->stripeSubscriptionId !== null && $membership->endsAt === null) {
                $couponId = $this->stripeCouponManager->getOrCreateCoupon($voucher);
                $this->stripeClient->subscriptions->update($membership->stripeSubscriptionId, [
                    'discounts' => [['coupon' => $couponId]],
                ]);

                $claim->markAsApplied($now);
                // Right away, so the membership page shows it - the subscription webhook that follows confirms it
                $membership->stripeDiscountCouponId = $couponId;

                $this->logger->info('Percentage voucher applied to existing subscription', [
                    'voucher_id' => $voucher->id->toString(),
                    'voucher_code' => $voucher->code,
                    'player_id' => $player->id->toString(),
                    'percentage_discount' => $voucher->percentageDiscount,
                    'subscription_id' => $membership->stripeSubscriptionId,
                ]);

                return new ClaimVoucherResult(
                    success: true,
                    voucherType: VoucherType::PercentageDiscount,
                    redirectToMembership: false,
                    percentageDiscount: $voucher->percentageDiscount,
                );
            }
        } catch (MembershipNotFound) {
            // Player has no membership, store voucher for later use
        }

        $player->claimDiscountVoucher($voucher);

        $this->logger->info('Percentage voucher claimed for future use', [
            'voucher_id' => $voucher->id->toString(),
            'voucher_code' => $voucher->code,
            'player_id' => $player->id->toString(),
            'percentage_discount' => $voucher->percentageDiscount,
        ]);

        return new ClaimVoucherResult(
            success: true,
            voucherType: VoucherType::PercentageDiscount,
            redirectToMembership: true,
            percentageDiscount: $voucher->percentageDiscount,
        );
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the free period the months cover
     */
    private function extendMembership(Membership $membership, \DateTimeImmutable $now, int $months): array
    {
        // The free months are added after everything the player already has: an earlier grant, or the
        // paid period a cancelled subscription still runs until - starting them today would eat that overlap
        $baseDate = max(
            $now,
            $membership->grantedUntil ?? $now,
            $membership->endsAt ?? $now,
        );

        $newGrantedUntil = $baseDate->add(new DateInterval('P' . $months . 'M'));
        $membership->grantedUntil = $newGrantedUntil;

        return [$baseDate, $newGrantedUntil];
    }

    /**
     * @return array{\DateTimeImmutable, \DateTimeImmutable} the free period: from the end of the paid period to the new trial end
     */
    private function applyFreeMonthsToSubscription(string $subscriptionId, int $months): array
    {
        $subscription = $this->stripeClient->subscriptions->retrieve($subscriptionId);
        $currentPeriodEnd = $subscription->items->data[0]->current_period_end;

        $paidPeriodEnd = (new \DateTimeImmutable())->setTimestamp($currentPeriodEnd);
        $trialEnd = $paidPeriodEnd->add(new DateInterval('P' . $months . 'M'));

        $this->stripeClient->subscriptions->update($subscriptionId, [
            'trial_end' => $trialEnd->getTimestamp(),
            'proration_behavior' => 'none',
        ]);

        $this->logger->info('Stripe subscription trial_end set for voucher', [
            'subscription_id' => $subscriptionId,
            'current_period_end' => date('Y-m-d H:i:s', $currentPeriodEnd),
            'trial_end' => $trialEnd->format('Y-m-d H:i:s'),
            'months_added' => $months,
        ]);

        return [$paidPeriodEnd, $trialEnd];
    }
}
