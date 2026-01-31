<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use DateInterval;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Exceptions\VoucherAlreadyUsed;
use SpeedPuzzling\Web\Exceptions\VoucherExpired;
use SpeedPuzzling\Web\Exceptions\VoucherNotFound;
use SpeedPuzzling\Web\Message\ClaimVoucher;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\VoucherRepository;
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
        private ClockInterface $clock,
        private LockFactory $lockFactory,
        private StripeClient $stripeClient,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws VoucherNotFound
     * @throws VoucherAlreadyUsed
     * @throws VoucherExpired
     */
    public function __invoke(ClaimVoucher $message): void
    {
        $lock = $this->lockFactory->createLock('voucher-claim-' . strtoupper(trim($message->voucherCode)));
        $lock->acquire(blocking: true);

        try {
            $voucher = $this->voucherRepository->getByCode($message->voucherCode);
            $player = $this->playerRepository->get($message->playerId);
            $now = $this->clock->now();

            if ($voucher->isUsed()) {
                throw new VoucherAlreadyUsed();
            }

            if ($voucher->isExpired($now)) {
                throw new VoucherExpired();
            }

            $voucherEndDate = $now->add(new DateInterval('P' . $voucher->monthsValue . 'M'));

            try {
                $membership = $this->membershipRepository->getByPlayerId($message->playerId);

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

            $this->logger->info('Voucher claimed successfully', [
                'voucher_id' => $voucher->id->toString(),
                'voucher_code' => $voucher->code,
                'player_id' => $message->playerId,
                'months_value' => $voucher->monthsValue,
            ]);
        } finally {
            $lock->release();
        }
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
