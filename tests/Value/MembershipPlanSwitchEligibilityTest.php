<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\MembershipPlan;
use SpeedPuzzling\Web\Value\MembershipPlanSwitchEligibility;
use Stripe\Subscription;

final class MembershipPlanSwitchEligibilityTest extends TestCase
{
    /**
     * @param array<string, mixed> $overrides
     * @param list<array<string, mixed>>|null $items
     */
    public static function subscription(string $lookupKey = 'puzzlership_monthly', array $overrides = [], null|array $items = null): Subscription
    {
        return Subscription::constructFrom(array_merge([
            'id' => 'sub_switch',
            'object' => 'subscription',
            'customer' => 'cus_switch',
            'status' => 'active',
            'cancel_at_period_end' => false,
            'cancel_at' => null,
            'pause_collection' => null,
            'schedule' => null,
            'pending_update' => null,
            'items' => [
                'object' => 'list',
                'data' => $items ?? [[
                    'id' => 'si_switch',
                    'object' => 'subscription_item',
                    'quantity' => 1,
                    'price' => ['id' => 'price_' . $lookupKey, 'object' => 'price', 'lookup_key' => $lookupKey, 'product' => 'prod_regular'],
                ]],
            ],
        ], $overrides));
    }

    public function testMonthlyMemberMaySwitchToYearly(): void
    {
        self::assertSame(MembershipPlan::Yearly, MembershipPlanSwitchEligibility::targetFor(self::subscription('puzzlership_monthly'), 'cus_switch'));
    }

    public function testYearlyMemberMaySwitchToMonthly(): void
    {
        self::assertSame(MembershipPlan::Monthly, MembershipPlanSwitchEligibility::targetFor(self::subscription('puzzlership_yearly'), 'cus_switch'));
    }

    /**
     * @return iterable<string, array{Subscription}>
     */
    public static function notSwitchable(): iterable
    {
        yield 'early adopter monthly' => [self::subscription('puzzlership_monthly_promo')];
        yield 'early adopter yearly' => [self::subscription('puzzlership_yearly_promo')];
        yield 'one-time year' => [self::subscription('puzzlership_onetime_year')];
        yield 'price without lookup key' => [self::subscription('', items: [['id' => 'si', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_x', 'object' => 'price', 'lookup_key' => null]]])];
        yield 'trialing' => [self::subscription(overrides: ['status' => 'trialing'])];
        yield 'past due' => [self::subscription(overrides: ['status' => 'past_due'])];
        yield 'incomplete' => [self::subscription(overrides: ['status' => 'incomplete'])];
        yield 'cancelled at period end' => [self::subscription(overrides: ['cancel_at_period_end' => true])];
        yield 'cancel date set' => [self::subscription(overrides: ['cancel_at' => 1_900_000_000])];
        yield 'paused by a voucher' => [self::subscription(overrides: ['pause_collection' => ['behavior' => 'void', 'resumes_at' => 1_900_000_000]])];
        yield 'change already booked' => [self::subscription(overrides: ['schedule' => 'sub_sched_1'])];
        yield 'earlier update waiting for payment' => [self::subscription(overrides: ['pending_update' => ['expires_at' => 1_900_000_000]])];
        yield 'two items' => [self::subscription(items: [
            ['id' => 'si_a', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_a', 'object' => 'price', 'lookup_key' => 'puzzlership_monthly']],
            ['id' => 'si_b', 'object' => 'subscription_item', 'quantity' => 1, 'price' => ['id' => 'price_b', 'object' => 'price', 'lookup_key' => 'puzzlership_monthly']],
        ])];
        yield 'quantity two' => [self::subscription(items: [['id' => 'si', 'object' => 'subscription_item', 'quantity' => 2, 'price' => ['id' => 'price_m', 'object' => 'price', 'lookup_key' => 'puzzlership_monthly']]])];
        yield 'another customer' => [self::subscription(overrides: ['customer' => 'cus_somebody_else'])];
    }

    #[DataProvider('notSwitchable')]
    public function testNoSwitch(Subscription $subscription): void
    {
        self::assertNull(MembershipPlanSwitchEligibility::targetFor($subscription, 'cus_switch'));
    }
}
