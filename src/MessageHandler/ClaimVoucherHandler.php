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
use SpeedPuzzling\Web\Value\VoucherType;
use Stripe\StripeClient;
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
     */
    public function __invoke(ClaimVoucher $message): ClaimVoucherResult
    {
        $lock = $this->lockFactory->createLock('voucher-claim-' . strtoupper(trim($message->voucherCode)));
        $lock->acquire(blocking: true);

        try {
            $voucher = $this->voucherRepository->getByCode($message->voucherCode);
            $player = $this->playerRepository->get($message->playerId);
            $now = $this->clock->now();

            if ($voucher->isExpired($now)) {
                throw new VoucherExpired();
            }

            if ($voucher->voucherType === VoucherType::FreeMonths) {
                return $this->handleFreeMonthsVoucher($voucher, $player, $now);
            }

            return $this->handlePercentageVoucher($voucher, $player, $now);
        } finally {
            $lock->release();
        }
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
                $this->pauseStripeSubscription($membership->stripeSubscriptionId, $voucherEndDate);
            }

            $this->extendMembership($membership, $now, $voucher->monthsValue);
        } catch (MembershipNotFound) {
            $membership = new Membership(
                id: Uuid::uuid7(),
                player: $player,
                createdAt: $now,
                endsAt: $voucherEndDate,
            );

            $this->membershipRepository->save($membership);
        }

        $voucher->markAsUsed($player, $now);

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
        );
    }

    /**
     * @throws VoucherUsageLimitReached
     * @throws PlayerAlreadyClaimedVoucher
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

        if ($this->voucherClaimRepository->hasPlayerClaimedVoucher($player->id->toString(), $voucher->id->toString())) {
            throw new PlayerAlreadyClaimedVoucher();
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
                    'coupon' => $couponId,
                ]);

                $claim->markAsApplied($now);

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

    private function extendMembership(Membership $membership, \DateTimeImmutable $now, int $months): void
    {
        $currentEndsAt = $membership->endsAt;

        if ($currentEndsAt === null || $currentEndsAt < $now) {
            $baseDate = $now;
        } else {
            $baseDate = $currentEndsAt;
        }

        $newEndsAt = $baseDate->add(new DateInterval('P' . $months . 'M'));
        $membership->endsAt = $newEndsAt;
    }

    private function pauseStripeSubscription(string $subscriptionId, \DateTimeImmutable $pauseUntil): void
    {
        $this->stripeClient->subscriptions->update($subscriptionId, [
            'pause_collection' => [
                'behavior' => 'void',
                'resumes_at' => $pauseUntil->getTimestamp(),
            ],
        ]);

        $this->logger->info('Stripe subscription paused for voucher', [
            'subscription_id' => $subscriptionId,
            'resumes_at' => $pauseUntil->format('Y-m-d H:i:s'),
        ]);
    }
}
