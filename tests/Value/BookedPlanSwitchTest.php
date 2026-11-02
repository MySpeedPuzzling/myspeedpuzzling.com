<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\BookedPlanSwitch;
use SpeedPuzzling\Web\Value\MembershipPlan;
use Stripe\Subscription;
use Stripe\SubscriptionSchedule;

final class BookedPlanSwitchTest extends TestCase
{
    public const int NOW = 1_793_700_000;
    private const int PERIOD_START = 1_793_660_214;
    private const int PERIOD_END = 1_825_196_214;

    /**
     * The shape Stripe's portal books for "switch to monthly at the period end" - read from a test-mode schedule
     * created through our plan switch configuration (2026-11-02).
     *
     * @param array<string, mixed> $overrides
     * @param null|list<array<string, mixed>> $phases
     */
    public static function schedule(array $overrides = [], null|array $phases = null, string $current = 'puzzlership_yearly', string $booked = 'puzzlership_monthly'): SubscriptionSchedule
    {
        return SubscriptionSchedule::constructFrom(array_merge([
            'id' => 'sub_sched_keep',
            'object' => 'subscription_schedule',
            'status' => 'active',
            'end_behavior' => 'release',
            'subscription' => 'sub_keep',
            'customer' => 'cus_keep',
            'released_at' => null,
            'canceled_at' => null,
            'phases' => $phases ?? [
                self::phase(self::PERIOD_START, self::PERIOD_END, $current),
                self::phase(self::PERIOD_END, self::PERIOD_END + 1, $booked),
            ],
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    public static function phase(int $start, int $end, string $lookupKey, int $quantity = 1): array
    {
        return [
            'start_date' => $start,
            'end_date' => $end,
            'items' => [['price' => ['id' => 'price_' . $lookupKey, 'object' => 'price', 'lookup_key' => $lookupKey], 'quantity' => $quantity]],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    public static function subscription(array $overrides = [], string $current = 'puzzlership_yearly'): Subscription
    {
        return MembershipPlanSwitchEligibilityTest::subscription($current, array_merge([
            'id' => 'sub_keep',
            'customer' => 'cus_keep',
            'schedule' => 'sub_sched_keep',
        ], $overrides));
    }

    public function testTheBookedSwitchToMonthlyMayBeUndone(): void
    {
        self::assertSame(MembershipPlan::Yearly, BookedPlanSwitch::keptPlan(self::subscription(), self::schedule(), 'cus_keep', self::NOW));
    }

    public function testABookedSwitchToYearlyMayBeUndoneToo(): void
    {
        self::assertSame(
            MembershipPlan::Monthly,
            BookedPlanSwitch::keptPlan(
                self::subscription(current: 'puzzlership_monthly'),
                self::schedule(current: 'puzzlership_monthly', booked: 'puzzlership_yearly'),
                'cus_keep',
                self::NOW,
            ),
        );
    }

    /**
     * @return iterable<string, array{Subscription, SubscriptionSchedule}>
     */
    public static function notReleasable(): iterable
    {
        yield 'another customer\'s subscription' => [self::subscription(['customer' => 'cus_other']), self::schedule()];
        yield 'schedule of another customer' => [self::subscription(), self::schedule(['customer' => 'cus_other'])];
        yield 'schedule of another subscription' => [self::subscription(), self::schedule(['subscription' => 'sub_other'])];
        yield 'subscription points at another schedule' => [self::subscription(['schedule' => 'sub_sched_other']), self::schedule()];
        yield 'schedule not running' => [self::subscription(), self::schedule(['status' => 'not_started'])];
        yield 'schedule already released' => [self::subscription(), self::schedule(['released_at' => self::NOW - 10])];
        yield 'schedule that cancels at its end' => [self::subscription(), self::schedule(['end_behavior' => 'cancel'])];
        yield 'subscription cancelling' => [self::subscription(['cancel_at_period_end' => true]), self::schedule()];
        yield 'subscription past due' => [self::subscription(['status' => 'past_due']), self::schedule()];
        yield 'subscription paused by a voucher' => [self::subscription(['pause_collection' => ['behavior' => 'void']]), self::schedule()];
        yield 'early adopter price' => [self::subscription(current: 'puzzlership_monthly_promo'), self::schedule(current: 'puzzlership_monthly_promo')];
        yield 'booked to an early adopter price' => [self::subscription(), self::schedule(booked: 'puzzlership_monthly_promo')];
        yield 'booked to the same plan' => [self::subscription(), self::schedule(booked: 'puzzlership_yearly')];
        yield 'running phase is another plan' => [self::subscription(), self::schedule(current: 'puzzlership_monthly')];
        yield 'three phases' => [self::subscription(), self::schedule(phases: [
            self::phase(self::PERIOD_START, self::PERIOD_END, 'puzzlership_yearly'),
            self::phase(self::PERIOD_END, self::PERIOD_END + 100, 'puzzlership_monthly'),
            self::phase(self::PERIOD_END + 100, self::PERIOD_END + 200, 'puzzlership_yearly'),
        ])];
        yield 'gap between the phases' => [self::subscription(), self::schedule(phases: [
            self::phase(self::PERIOD_START, self::PERIOD_END, 'puzzlership_yearly'),
            self::phase(self::PERIOD_END + 86_400, self::PERIOD_END + 86_401, 'puzzlership_monthly'),
        ])];
        yield 'running phase starts later' => [self::subscription(), self::schedule(phases: [
            self::phase(self::NOW + 10, self::PERIOD_END, 'puzzlership_yearly'),
            self::phase(self::PERIOD_END, self::PERIOD_END + 1, 'puzzlership_monthly'),
        ])];
        yield 'running phase already over' => [self::subscription(), self::schedule(phases: [
            self::phase(self::PERIOD_START, self::NOW - 10, 'puzzlership_yearly'),
            self::phase(self::NOW - 10, self::NOW - 9, 'puzzlership_monthly'),
        ])];
        yield 'quantity two booked' => [self::subscription(), self::schedule(phases: [
            self::phase(self::PERIOD_START, self::PERIOD_END, 'puzzlership_yearly'),
            self::phase(self::PERIOD_END, self::PERIOD_END + 1, 'puzzlership_monthly', quantity: 2),
        ])];
    }

    #[DataProvider('notReleasable')]
    public function testLeftAlone(Subscription $subscription, SubscriptionSchedule $schedule): void
    {
        self::assertNull(BookedPlanSwitch::keptPlan($subscription, $schedule, 'cus_keep', self::NOW));
    }
}
