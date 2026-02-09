<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\MessageHandler\UpdateMembershipSubscriptionHandler;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use Stripe\Customer;
use Stripe\Service\CustomerService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class UpdateMembershipSubscriptionHandlerTest extends TestCase
{
    public function testSkipsProcessingWhenSubscriptionHasPauseCollection(): void
    {
        $subscriptionId = 'sub_test_paused';
        $voucherEndsAt = new DateTimeImmutable('+3 months');

        $subscription = Subscription::constructFrom([
            'id' => $subscriptionId,
            'status' => 'active',
            'customer' => 'cus_test_123',
            'cancel_at_period_end' => false,
            'pause_collection' => [
                'behavior' => 'void',
                'resumes_at' => $voucherEndsAt->getTimestamp(),
            ],
            'items' => [
                'data' => [
                    [
                        'id' => 'si_test',
                        'current_period_end' => (new DateTimeImmutable('+30 days'))->getTimestamp(),
                    ],
                ],
            ],
        ]);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->with($subscriptionId)
            ->willReturn($subscription);

        $stripeClient = $this->createMock(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                default => null,
            },
        );

        $membershipRepository = $this->createMock(MembershipRepository::class);
        $playerRepository = $this->createMock(PlayerRepository::class);

        // Neither repository should be called since we return early
        $membershipRepository->expects(self::never())->method('getByStripeSubscriptionId');
        $membershipRepository->expects(self::never())->method('getByPlayerId');

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            lockFactory: new LockFactory(new InMemoryStore()),
            playerRepository: $playerRepository,
            clock: new MockClock(),
        );

        $handler(new UpdateMembershipSubscription($subscriptionId));
    }

    public function testProcessesNormallyWhenNoPauseCollection(): void
    {
        $subscriptionId = 'sub_test_active';
        $playerId = Uuid::uuid7();

        $player = new Player(
            id: $playerId,
            code: 'testplayer',
            userId: 'auth0|test',
            email: 'test@example.com',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: new DateTimeImmutable('-30 days'),
            stripeSubscriptionId: $subscriptionId,
            billingPeriodEndsAt: new DateTimeImmutable('+15 days'),
            endsAt: null,
        );
        // Clear events from constructor
        $membership->popEvents();

        $billingPeriodEnd = new DateTimeImmutable('+30 days');

        $subscription = Subscription::constructFrom([
            'id' => $subscriptionId,
            'status' => 'active',
            'customer' => 'cus_test_123',
            'cancel_at_period_end' => false,
            'pause_collection' => null,
            'items' => [
                'data' => [
                    [
                        'id' => 'si_test',
                        'current_period_end' => $billingPeriodEnd->getTimestamp(),
                    ],
                ],
            ],
        ]);

        $customer = Customer::constructFrom([
            'id' => 'cus_test_123',
            'metadata' => ['player_id' => $playerId->toString()],
        ]);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->with($subscriptionId)
            ->willReturn($subscription);

        $customerService = $this->createMock(CustomerService::class);
        $customerService->method('retrieve')
            ->with('cus_test_123')
            ->willReturn($customer);

        $stripeClient = $this->createMock(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                default => null,
            },
        );

        $membershipRepository = $this->createMock(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->with($subscriptionId)
            ->willReturn($membership);

        $playerRepository = $this->createMock(PlayerRepository::class);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            lockFactory: new LockFactory(new InMemoryStore()),
            playerRepository: $playerRepository,
            clock: new MockClock(),
        );

        $handler(new UpdateMembershipSubscription($subscriptionId, isPaymentConfirmed: true));

        // Membership should be updated - endsAt set to null for active subscription
        self::assertNull($membership->endsAt);
        // Should have recorded a renewal event
        $events = $membership->popEvents();
        self::assertNotEmpty($events);
    }

    public function testNoRenewalEventWhenPaymentNotConfirmed(): void
    {
        $subscriptionId = 'sub_test_active';
        $playerId = Uuid::uuid7();

        $player = new Player(
            id: $playerId,
            code: 'testplayer',
            userId: 'auth0|test',
            email: 'test@example.com',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: new DateTimeImmutable('-30 days'),
            stripeSubscriptionId: $subscriptionId,
            billingPeriodEndsAt: new DateTimeImmutable('+15 days'),
            endsAt: null,
        );
        // Clear events from constructor
        $membership->popEvents();

        $billingPeriodEnd = new DateTimeImmutable('+30 days');

        $subscription = Subscription::constructFrom([
            'id' => $subscriptionId,
            'status' => 'active',
            'customer' => 'cus_test_123',
            'cancel_at_period_end' => false,
            'pause_collection' => null,
            'items' => [
                'data' => [
                    [
                        'id' => 'si_test',
                        'current_period_end' => $billingPeriodEnd->getTimestamp(),
                    ],
                ],
            ],
        ]);

        $customer = Customer::constructFrom([
            'id' => 'cus_test_123',
            'metadata' => ['player_id' => $playerId->toString()],
        ]);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->with($subscriptionId)
            ->willReturn($subscription);

        $customerService = $this->createMock(CustomerService::class);
        $customerService->method('retrieve')
            ->with('cus_test_123')
            ->willReturn($customer);

        $stripeClient = $this->createMock(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                default => null,
            },
        );

        $membershipRepository = $this->createMock(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->with($subscriptionId)
            ->willReturn($membership);

        $playerRepository = $this->createMock(PlayerRepository::class);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            lockFactory: new LockFactory(new InMemoryStore()),
            playerRepository: $playerRepository,
            clock: new MockClock(),
        );

        $handler(new UpdateMembershipSubscription($subscriptionId, isPaymentConfirmed: false));

        // Membership should still be active - endsAt set to null
        self::assertNull($membership->endsAt);
        // Stripe subscription ID should be updated
        self::assertSame($subscriptionId, $membership->stripeSubscriptionId);
        // Billing period should NOT be updated (payment not confirmed)
        self::assertNotEquals($billingPeriodEnd, $membership->billingPeriodEndsAt);
        // Should NOT have recorded any events
        $events = $membership->popEvents();
        self::assertEmpty($events);
    }
}
