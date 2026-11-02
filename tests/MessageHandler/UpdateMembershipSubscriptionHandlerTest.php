<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Tests\TestClock;
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
use Stripe\Service\SubscriptionScheduleService;
use Stripe\Service\SubscriptionService;
use Stripe\SubscriptionSchedule;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;

final class UpdateMembershipSubscriptionHandlerTest extends TestCase
{
    public function testSkipsProcessingWhenSubscriptionHasPauseCollection(): void
    {
        $subscriptionId = 'sub_test_paused';
        $voucherEndsAt = TestClock::now()->modify('+3 months');

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
                        'current_period_end' => (TestClock::now()->modify('+30 days'))->getTimestamp(),
                    ],
                ],
            ],
        ]);

        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($subscription);

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                default => null,
            },
        );

        $membershipRepository = $this->createMock(MembershipRepository::class);
        $playerRepository = $this->createStub(PlayerRepository::class);

        // Neither repository should be called since we return early
        $membershipRepository->expects(self::never())->method('getByStripeSubscriptionId');
        $membershipRepository->expects(self::never())->method('getByPlayerId');

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            playerRepository: $playerRepository,
            clock: new MockClock(TestClock::now()),
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
            name: 'Test Player',
            registeredAt: TestClock::now(),
        );
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: TestClock::now()->modify('-30 days'),
            stripeSubscriptionId: $subscriptionId,
            billingPeriodEndsAt: TestClock::now()->modify('+15 days'),
            endsAt: null,
        );
        // Clear events from constructor
        $membership->popEvents();

        $billingPeriodEnd = TestClock::now()->modify('+30 days');

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

        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($subscription);

        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')
            ->willReturn($customer);

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                default => null,
            },
        );

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->willReturn($membership);

        $playerRepository = $this->createStub(PlayerRepository::class);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            playerRepository: $playerRepository,
            clock: new MockClock(TestClock::now()),
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
            name: 'Test Player',
            registeredAt: TestClock::now(),
        );
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: TestClock::now()->modify('-30 days'),
            stripeSubscriptionId: $subscriptionId,
            billingPeriodEndsAt: TestClock::now()->modify('+15 days'),
            endsAt: null,
        );
        // Clear events from constructor
        $membership->popEvents();

        $billingPeriodEnd = TestClock::now()->modify('+30 days');

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

        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($subscription);

        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')
            ->willReturn($customer);

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                default => null,
            },
        );

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->willReturn($membership);

        $playerRepository = $this->createStub(PlayerRepository::class);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            playerRepository: $playerRepository,
            clock: new MockClock(TestClock::now()),
        );

        $handler(new UpdateMembershipSubscription($subscriptionId, isPaymentConfirmed: false));

        // Membership should still be active - endsAt set to null
        self::assertNull($membership->endsAt);
        // Stripe subscription ID should be updated
        self::assertSame($subscriptionId, $membership->stripeSubscriptionId);
        // Billing period SHOULD be updated even without payment confirmation (fixes 1-hour gap)
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($billingPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());
        // Should NOT have recorded any events (renewal event only on payment confirmation)
        $events = $membership->popEvents();
        self::assertEmpty($events);
    }

    public function testStoresTheCouponCurrentlyOnTheSubscription(): void
    {
        $membership = $this->membershipWithSubscription('sub_test_discounted');
        $membership->stripeDiscountCouponId = 'coupon_stale';

        $this->handleWebhook($membership, 'sub_test_discounted', discounts: [
            [
                'id' => 'di_test',
                'object' => 'discount',
                'source' => ['type' => 'coupon', 'coupon' => 'coupon_voucher_20'],
            ],
        ]);

        self::assertSame('coupon_voucher_20', $membership->stripeDiscountCouponId);
    }

    public function testClearsTheCouponOnceTheSubscriptionHasNone(): void
    {
        $membership = $this->membershipWithSubscription('sub_test_plain');
        $membership->stripeDiscountCouponId = 'coupon_voucher_20';

        $this->handleWebhook($membership, 'sub_test_plain', discounts: []);

        self::assertNull($membership->stripeDiscountCouponId);
    }

    private function membershipWithSubscription(string $subscriptionId): Membership
    {
        $player = new Player(
            id: Uuid::uuid7(),
            code: 'discounted',
            userId: 'auth0|discounted',
            name: 'Discounted Player',
            registeredAt: TestClock::now(),
        );

        return new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: TestClock::now()->modify('-30 days'),
            stripeSubscriptionId: $subscriptionId,
            billingPeriodEndsAt: TestClock::now()->modify('+15 days'),
        );
    }

    /**
     * @param list<array<string, mixed>> $discounts
     */
    private function handleWebhook(Membership $membership, string $subscriptionId, array $discounts): void
    {
        $subscription = Subscription::constructFrom([
            'id' => $subscriptionId,
            'status' => 'active',
            'customer' => 'cus_test_discounted',
            'cancel_at_period_end' => false,
            'pause_collection' => null,
            'discounts' => $discounts,
            'items' => [
                'data' => [
                    [
                        'id' => 'si_test',
                        'current_period_end' => (TestClock::now()->modify('+30 days'))->getTimestamp(),
                    ],
                ],
            ],
        ]);

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::once())
            ->method('retrieve')
            ->with($subscriptionId, ['expand' => ['discounts']])
            ->willReturn($subscription);

        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')->willReturn(Customer::constructFrom([
            'id' => 'cus_test_discounted',
            'metadata' => ['player_id' => $membership->player->id->toString()],
        ]));

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                default => null,
            },
        );

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')->willReturn($membership);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            playerRepository: $this->createStub(PlayerRepository::class),
            clock: new MockClock(TestClock::now()),
        );

        $handler(new UpdateMembershipSubscription($subscriptionId));
    }

    public function testStoresThePriceTheSubscriptionIsOn(): void
    {
        [$membership, $handler] = $this->planScenario(lookupKey: 'puzzlership_yearly', schedule: null);

        $handler(new UpdateMembershipSubscription('sub_plan'));

        self::assertSame('puzzlership_yearly', $membership->stripePriceLookupKey);
        self::assertNull($membership->scheduledPriceLookupKey);
        self::assertNull($membership->scheduledPriceChangeAt);
    }

    public function testStoresAChangeBookedForTheEndOfThePaidYear(): void
    {
        $changeAt = TestClock::now()->modify('+200 days')->setTime(10, 0);

        $schedule = SubscriptionSchedule::constructFrom([
            'id' => 'sub_sched_plan',
            'object' => 'subscription_schedule',
            'phases' => [
                [
                    'start_date' => TestClock::now()->modify('-165 days')->getTimestamp(),
                    'end_date' => $changeAt->getTimestamp(),
                    'items' => [['price' => ['id' => 'price_y', 'object' => 'price', 'lookup_key' => 'puzzlership_yearly'], 'quantity' => 1]],
                ],
                [
                    'start_date' => $changeAt->getTimestamp(),
                    'end_date' => null,
                    'items' => [['price' => ['id' => 'price_m', 'object' => 'price', 'lookup_key' => 'puzzlership_monthly'], 'quantity' => 1]],
                ],
            ],
        ]);

        [$membership, $handler] = $this->planScenario(lookupKey: 'puzzlership_yearly', schedule: $schedule);

        $handler(new UpdateMembershipSubscription('sub_plan'));

        self::assertSame('puzzlership_yearly', $membership->stripePriceLookupKey);
        self::assertSame('puzzlership_monthly', $membership->scheduledPriceLookupKey);
        self::assertEquals($changeAt, $membership->scheduledPriceChangeAt);
        // The billing period is untouched by a booked change
        self::assertEquals(TestClock::now()->modify('+200 days')->setTime(10, 0), $membership->billingPeriodEndsAt);
    }

    public function testAnUpgradeToYearlyMovesTheBillingPeriodToTheNewYear(): void
    {
        [$membership, $handler] = $this->planScenario(lookupKey: 'puzzlership_yearly', schedule: null, periodEnd: TestClock::now()->modify('+365 days')->setTime(10, 0));
        $membership->stripePriceLookupKey = 'puzzlership_monthly';

        $handler(new UpdateMembershipSubscription('sub_plan'));

        self::assertSame('puzzlership_yearly', $membership->stripePriceLookupKey);
        self::assertEquals(TestClock::now()->modify('+365 days')->setTime(10, 0), $membership->billingPeriodEndsAt);
        self::assertNull($membership->endsAt);
    }

    /**
     * @return array{Membership, UpdateMembershipSubscriptionHandler}
     */
    private function planScenario(string $lookupKey, null|SubscriptionSchedule $schedule, null|\DateTimeImmutable $periodEnd = null): array
    {
        $playerId = Uuid::uuid7();
        $periodEnd ??= TestClock::now()->modify('+200 days')->setTime(10, 0);

        $player = new Player(id: $playerId, code: 'planplayer', userId: 'auth0|plan', name: 'Plan Player', registeredAt: TestClock::now());
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $player,
            createdAt: TestClock::now()->modify('-165 days'),
            stripeSubscriptionId: 'sub_plan',
            billingPeriodEndsAt: TestClock::now()->modify('+10 days'),
        );
        $membership->popEvents();

        $subscription = Subscription::constructFrom([
            'id' => 'sub_plan',
            'object' => 'subscription',
            'status' => 'active',
            'customer' => 'cus_plan',
            'cancel_at_period_end' => false,
            'pause_collection' => null,
            'schedule' => $schedule?->id,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_plan',
                    'object' => 'subscription_item',
                    'current_period_end' => $periodEnd->getTimestamp(),
                    'price' => ['id' => 'price_current', 'object' => 'price', 'lookup_key' => $lookupKey],
                ]],
            ],
        ]);

        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')->willReturn($subscription);

        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')->willReturn(Customer::constructFrom(['id' => 'cus_plan', 'metadata' => ['player_id' => $playerId->toString()]]));

        $scheduleService = $this->createMock(SubscriptionScheduleService::class);
        if ($schedule === null) {
            $scheduleService->expects(self::never())->method('retrieve');
        } else {
            $scheduleService->expects(self::once())->method('retrieve')
                ->with($schedule->id, ['expand' => ['phases.items.price']])
                ->willReturn($schedule);
        }

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                'subscriptionSchedules' => $scheduleService,
                default => null,
            },
        );

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')->willReturn($membership);

        $handler = new UpdateMembershipSubscriptionHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            logger: new NullLogger(),
            playerRepository: $this->createStub(PlayerRepository::class),
            clock: new MockClock(TestClock::now()),
        );

        return [$membership, $handler];
    }
}
