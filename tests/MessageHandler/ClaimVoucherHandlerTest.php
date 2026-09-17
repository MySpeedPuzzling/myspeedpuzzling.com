<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

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
use SpeedPuzzling\Web\Tests\DataFixtures\MembershipFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\VoucherFixture;
use SpeedPuzzling\Web\Value\LifetimeMembership;
use SpeedPuzzling\Web\Value\VoucherType;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class ClaimVoucherHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private VoucherRepository $voucherRepository;
    private MembershipRepository $membershipRepository;
    private PlayerRepository $playerRepository;
    private VoucherClaimRepository $voucherClaimRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);
        $this->voucherRepository = $container->get(VoucherRepository::class);
        $this->membershipRepository = $container->get(MembershipRepository::class);
        $this->playerRepository = $container->get(PlayerRepository::class);
        $this->voucherClaimRepository = $container->get(VoucherClaimRepository::class);
    }

    public function testClaimingVoucherCreatesNewMembership(): void
    {
        // Use a player without existing membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_AVAILABLE_CODE;

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Verify voucher is marked as used
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($voucher->isUsed());
        self::assertNotNull($voucher->usedAt);
        self::assertNotNull($voucher->usedBy);
        self::assertSame($playerId, $voucher->usedBy->id->toString());

        // Verify membership was created with grantedUntil (not endsAt)
        $membership = $this->membershipRepository->getByPlayerId($playerId);
        self::assertNotNull($membership->grantedUntil);
        self::assertNull($membership->endsAt);

        // The free period is recorded, so the membership page can show what the voucher covers
        self::assertEquals($voucher->usedAt, $voucher->freePeriodStartsAt);
        self::assertEquals($membership->grantedUntil, $voucher->freePeriodEndsAt);
    }

    public function testFreeMonthsOnRunningSubscriptionStartAfterThePaidPeriod(): void
    {
        $paidPeriodEnd = new \DateTimeImmutable('2026-10-02 19:56:10');

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::once())
            ->method('retrieve')
            ->with('sub_test_123456789')
            ->willReturn(Subscription::constructFrom([
                'id' => 'sub_test_123456789',
                'status' => 'active',
                'items' => [
                    'object' => 'list',
                    'data' => [['id' => 'si_test', 'current_period_end' => $paidPeriodEnd->getTimestamp()]],
                ],
            ]));
        $subscriptionService->expects(self::once())
            ->method('update')
            ->with('sub_test_123456789', [
                'trial_end' => $paidPeriodEnd->modify('+1 month')->getTimestamp(),
                'proration_behavior' => 'none',
            ]);
        $this->replaceStripeSubscriptions($subscriptionService);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                voucherCode: VoucherFixture::VOUCHER_AVAILABLE_CODE,
            ),
        );

        $voucher = $this->voucherRepository->getByCode(VoucherFixture::VOUCHER_AVAILABLE_CODE);
        self::assertNotNull($voucher->freePeriodStartsAt);
        self::assertNotNull($voucher->freePeriodEndsAt);
        self::assertSame($paidPeriodEnd->getTimestamp(), $voucher->freePeriodStartsAt->getTimestamp());
        self::assertSame($paidPeriodEnd->modify('+1 month')->getTimestamp(), $voucher->freePeriodEndsAt->getTimestamp());

        $membership = $this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE);
        self::assertEquals($voucher->freePeriodEndsAt, $membership->billingPeriodEndsAt);
    }

    public function testFreeMonthsAfterCancelledSubscriptionStartWhenThePaidPeriodEnds(): void
    {
        $paidUntil = new \DateTimeImmutable('+20 days 12:00:00');

        $membership = $this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE);
        $membership->cancel($paidUntil);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                voucherCode: VoucherFixture::VOUCHER_AVAILABLE_CODE,
            ),
        );

        $voucher = $this->voucherRepository->getByCode(VoucherFixture::VOUCHER_AVAILABLE_CODE);
        self::assertEquals($paidUntil, $voucher->freePeriodStartsAt);
        self::assertEquals($paidUntil->modify('+1 month'), $voucher->freePeriodEndsAt);

        $membership = $this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE);
        self::assertEquals($paidUntil->modify('+1 month'), $membership->grantedUntil);
    }

    public function testReclaimingOwnFreeMonthsVoucherIsReportedAsAlreadyClaimed(): void
    {
        // VOUCHER_USED was redeemed by PLAYER_REGULAR - entering it again must not read as "used by someone"
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    voucherCode: VoucherFixture::VOUCHER_USED_CODE,
                ),
            );
            self::fail('Expected PlayerAlreadyClaimedVoucher exception was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(PlayerAlreadyClaimedVoucher::class, $e->getPrevious());
        }
    }

    public function testReclaimingOwnLifetimeVoucherIsReportedAsAlreadyClaimed(): void
    {
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: $playerId,
                    voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
                ),
            );
            self::fail('Expected PlayerAlreadyClaimedVoucher exception was not thrown');
        } catch (HandlerFailedException $e) {
            // Not PlayerAlreadyHasLifetimeMembership - its "pass it on" advice makes no sense for a spent code
            self::assertInstanceOf(PlayerAlreadyClaimedVoucher::class, $e->getPrevious());
        }
    }

    public function testClaimingVoucherWithInvalidCodeThrowsException(): void
    {
        $this->expectException(VoucherNotFound::class);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_REGULAR,
                voucherCode: 'INVALIDCODE12345',
            ),
        );
    }

    public function testClaimingAlreadyUsedVoucherThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    // VOUCHER_USED belongs to PLAYER_REGULAR
                    playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                    voucherCode: VoucherFixture::VOUCHER_USED_CODE,
                ),
            );
            self::fail('Expected VoucherAlreadyUsed exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherAlreadyUsed::class, $previous);
        }
    }

    public function testClaimingExpiredVoucherThrowsException(): void
    {
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_REGULAR,
                    voucherCode: VoucherFixture::VOUCHER_EXPIRED_CODE,
                ),
            );
            self::fail('Expected VoucherExpired exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherExpired::class, $previous);
        }
    }

    public function testVoucherCodeIsCaseInsensitive(): void
    {
        $playerId = PlayerFixture::PLAYER_PRIVATE;
        $voucherCode = strtolower(VoucherFixture::VOUCHER_AVAILABLE_CODE);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Verify voucher is marked as used
        $voucher = $this->voucherRepository->getByCode(VoucherFixture::VOUCHER_AVAILABLE_CODE);
        self::assertTrue($voucher->isUsed());
    }

    public function testClaimingPercentageVoucherCreatesClaimAndStoresOnPlayer(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership, so won't call Stripe
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertTrue($result->success);
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
        self::assertTrue($result->redirectToMembership);
        self::assertSame(20, $result->percentageDiscount);

        // Verify voucher claim was created
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher($playerId, $voucher->id->toString()));

        // Verify voucher is stored on player for future checkout
        $player = $this->playerRepository->get($playerId);
        self::assertNotNull($player->claimedDiscountVoucher);
        self::assertSame($voucher->id->toString(), $player->claimedDiscountVoucher->id->toString());
    }

    public function testClaimingPercentageVoucherResultHasCorrectType(): void
    {
        // Use PLAYER_PRIVATE - has no membership, so won't call Stripe
        $playerId = PlayerFixture::PLAYER_PRIVATE;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
        self::assertSame(20, $result->percentageDiscount);
    }

    public function testClaimingExpiredPercentageVoucherThrowsException(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                    voucherCode: VoucherFixture::VOUCHER_PERCENTAGE_EXPIRED_CODE,
                ),
            );
            self::fail('Expected VoucherExpired exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherExpired::class, $previous);
        }
    }

    public function testClaimingPercentageVoucherWhenMaxUsesReachedThrowsException(): void
    {
        // Use PLAYER_PRIVATE - has no membership (PLAYER_REGULAR already claimed this voucher in fixture)
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_PRIVATE,
                    voucherCode: VoucherFixture::VOUCHER_PERCENTAGE_MAX_USES_REACHED_CODE,
                ),
            );
            self::fail('Expected VoucherUsageLimitReached exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(VoucherUsageLimitReached::class, $previous);
        }
    }

    public function testClaimingSamePercentageVoucherTwiceThrowsException(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        // First claim should succeed
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        // Second claim by same player should fail
        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: $playerId,
                    voucherCode: $voucherCode,
                ),
            );
            self::fail('Expected PlayerAlreadyClaimedVoucher exception was not thrown');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(PlayerAlreadyClaimedVoucher::class, $previous);
        }
    }

    public function testMultiplePlayersCanClaimSamePercentageVoucher(): void
    {
        $voucherCode = VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE;

        // First player claims (PLAYER_WITH_FAVORITES - no membership)
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                voucherCode: $voucherCode,
            ),
        );

        // Second player claims (PLAYER_PRIVATE - no membership)
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_PRIVATE,
                voucherCode: $voucherCode,
            ),
        );

        // Verify both claims exist
        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher(
            PlayerFixture::PLAYER_WITH_FAVORITES,
            $voucher->id->toString(),
        ));
        self::assertTrue($this->voucherClaimRepository->hasPlayerClaimedVoucher(
            PlayerFixture::PLAYER_PRIVATE,
            $voucher->id->toString(),
        ));

        // Verify usage count
        self::assertSame(2, $this->voucherClaimRepository->countClaimsForVoucher($voucher->id->toString()));
    }

    public function testPercentageVoucherCodeIsCaseInsensitive(): void
    {
        // Use PLAYER_WITH_FAVORITES - has no membership
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = strtolower(VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE);

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertTrue($result->success);
        self::assertSame(VoucherType::PercentageDiscount, $result->voucherType);
    }

    public function testClaimingLifetimeVoucherCreatesLifetimeMembership(): void
    {
        // PLAYER_WITH_FAVORITES has no membership, so Stripe is never asked about a subscription
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;
        $voucherCode = VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE;

        $envelope = $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: $voucherCode,
            ),
        );

        /** @var HandledStamp|null $handledStamp */
        $handledStamp = $envelope->last(HandledStamp::class);
        self::assertNotNull($handledStamp);

        /** @var ClaimVoucherResult $result */
        $result = $handledStamp->getResult();
        self::assertTrue($result->success);
        self::assertSame(VoucherType::Lifetime, $result->voucherType);
        self::assertFalse($result->redirectToMembership);

        $voucher = $this->voucherRepository->getByCode($voucherCode);
        self::assertTrue($voucher->isUsed());
        self::assertNotNull($voucher->usedBy);
        self::assertSame($playerId, $voucher->usedBy->id->toString());

        $membership = $this->membershipRepository->getByPlayerId($playerId);
        self::assertTrue($membership->hasLifetimeGrant());
        self::assertSame(LifetimeMembership::GRANTED_UNTIL, $membership->grantedUntil?->format('Y-m-d H:i:s'));
        self::assertNull($membership->endsAt);
        self::assertNull($membership->stripeSubscriptionId);
    }

    public function testClaimingLifetimeVoucherReplacesExistingGrant(): void
    {
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;

        // Free months voucher first - leaves a membership granted for a month
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: VoucherFixture::VOUCHER_AVAILABLE_CODE,
            ),
        );
        self::assertFalse($this->membershipRepository->getByPlayerId($playerId)->hasLifetimeGrant());

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        $membership = $this->membershipRepository->getByPlayerId($playerId);
        self::assertTrue($membership->hasLifetimeGrant());
    }

    public function testClaimingLifetimeVoucherCancelsActiveSubscriptionAtPeriodEnd(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::once())
            ->method('retrieve')
            ->with('sub_test_123456789')
            ->willReturn($this->subscription('active'));
        $subscriptionService->expects(self::once())
            ->method('update')
            ->with('sub_test_123456789', ['cancel_at_period_end' => true]);
        $subscriptionService->expects(self::never())->method('cancel');
        $this->replaceStripeSubscriptions($subscriptionService);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        $membership = $this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE);
        self::assertTrue($membership->hasLifetimeGrant());
        // The paid period keeps running - the cancellation webhook moves ends_at, granted_until keeps access
        self::assertSame('sub_test_123456789', $membership->stripeSubscriptionId);
        self::assertNull($membership->endsAt);
    }

    public function testClaimingLifetimeVoucherCancelsPastDueSubscriptionImmediately(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::once())
            ->method('retrieve')
            ->willReturn($this->subscription('past_due'));
        $subscriptionService->expects(self::never())->method('update');
        $subscriptionService->expects(self::once())
            ->method('cancel')
            ->with('sub_test_123456789');
        $this->replaceStripeSubscriptions($subscriptionService);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        self::assertTrue($this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE)->hasLifetimeGrant());
    }

    public function testClaimingLifetimeVoucherLeavesAlreadyCancelledSubscriptionAlone(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::once())
            ->method('retrieve')
            ->willReturn($this->subscription('active', cancelAtPeriodEnd: true));
        $subscriptionService->expects(self::never())->method('update');
        $subscriptionService->expects(self::never())->method('cancel');
        $this->replaceStripeSubscriptions($subscriptionService);

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        self::assertTrue($this->membershipRepository->get(MembershipFixture::MEMBERSHIP_ACTIVE)->hasLifetimeGrant());
    }

    public function testLifetimeVoucherCanBeClaimedOnlyOnce(): void
    {
        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: PlayerFixture::PLAYER_WITH_FAVORITES,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: PlayerFixture::PLAYER_PRIVATE,
                    voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
                ),
            );
            self::fail('Expected VoucherAlreadyUsed exception was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(VoucherAlreadyUsed::class, $e->getPrevious());
        }
    }

    public function testLifetimeMemberCannotClaimAnotherVoucher(): void
    {
        $playerId = PlayerFixture::PLAYER_WITH_FAVORITES;

        $this->messageBus->dispatch(
            new ClaimVoucher(
                playerId: $playerId,
                voucherCode: VoucherFixture::VOUCHER_LIFETIME_AVAILABLE_CODE,
            ),
        );

        try {
            $this->messageBus->dispatch(
                new ClaimVoucher(
                    playerId: $playerId,
                    voucherCode: VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE,
                ),
            );
            self::fail('Expected PlayerAlreadyHasLifetimeMembership exception was not thrown');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(PlayerAlreadyHasLifetimeMembership::class, $e->getPrevious());
        }

        // The voucher stays untouched for someone it can actually help
        $voucher = $this->voucherRepository->getByCode(VoucherFixture::VOUCHER_PERCENTAGE_AVAILABLE_CODE);
        self::assertFalse($this->voucherClaimRepository->hasPlayerClaimedVoucher($playerId, $voucher->id->toString()));
    }

    private function replaceStripeSubscriptions(SubscriptionService $subscriptionService): void
    {
        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                default => null,
            },
        );

        self::getContainer()->set(StripeClient::class, $stripeClient);
    }

    private function subscription(string $status, bool $cancelAtPeriodEnd = false): Subscription
    {
        return Subscription::constructFrom([
            'id' => 'sub_test_123456789',
            'status' => $status,
            'cancel_at_period_end' => $cancelAtPeriodEnd,
        ]);
    }
}
