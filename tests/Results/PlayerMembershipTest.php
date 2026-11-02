<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerMembership;
use SpeedPuzzling\Web\Value\MembershipPlan;

final class PlayerMembershipTest extends TestCase
{
    public function testActiveSubscriptionIsActive(): void
    {
        $now = new DateTimeImmutable('2026-03-23');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: null,
            billingPeriodEndsAt: new DateTimeImmutable('2026-04-23'),
            grantedUntil: null,
        );

        self::assertTrue($membership->isActive($now));
        self::assertFalse($membership->hasActiveGrant($now));
    }

    public function testGrantedMembershipIsActive(): void
    {
        $now = new DateTimeImmutable('2026-03-23');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-06-01'),
        );

        self::assertTrue($membership->isActive($now));
        self::assertTrue($membership->hasActiveGrant($now));
    }

    public function testExpiredGrantIsInactive(): void
    {
        $now = new DateTimeImmutable('2026-03-23');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-01-01'),
        );

        self::assertFalse($membership->isActive($now));
    }

    public function testCancelledSubscriptionWithActiveGrantIsStillActive(): void
    {
        $now = new DateTimeImmutable('2026-04-25');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: new DateTimeImmutable('2026-04-23'),
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-06-01'),
        );

        // endsAt is in the past, but grantedUntil is still valid
        self::assertTrue($membership->isActive($now));
        self::assertTrue($membership->hasActiveGrant($now));
    }

    public function testPausedSubscriptionWithActiveGrantIsStillActive(): void
    {
        $now = new DateTimeImmutable('2026-03-23 02:00:00');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: new DateTimeImmutable('2026-03-23 01:30:00'),
            billingPeriodEndsAt: new DateTimeImmutable('2026-04-23'),
            grantedUntil: new DateTimeImmutable('2026-06-01'),
        );

        // Subscription paused (endsAt = past), but grant is active
        self::assertTrue($membership->isActive($now));
    }

    public function testFullyExpiredMembershipIsInactive(): void
    {
        $now = new DateTimeImmutable('2026-07-01');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: new DateTimeImmutable('2026-04-23'),
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-06-01'),
        );

        // Both endsAt and grantedUntil are in the past
        self::assertFalse($membership->isActive($now));
    }

    public function testActiveUntilPrefersTheLaterOfCancelledPeriodAndGrant(): void
    {
        $now = new DateTimeImmutable('2026-04-01');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: new DateTimeImmutable('2026-04-23'),
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-10-23'),
        );

        self::assertEquals(new DateTimeImmutable('2026-10-23'), $membership->activeUntil($now));
    }

    public function testActiveUntilIgnoresDatesInThePast(): void
    {
        $now = new DateTimeImmutable('2026-05-01');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: new DateTimeImmutable('2026-06-01'),
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-04-01'),
        );

        self::assertEquals(new DateTimeImmutable('2026-06-01'), $membership->activeUntil($now));
        self::assertNull($membership->activeUntil(new DateTimeImmutable('2026-07-01')));
    }

    public function testRunningFreeTrial(): void
    {
        $now = new DateTimeImmutable('2026-09-20 10:00:00');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-09-30 10:00:00'),
            trialEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
        );

        self::assertTrue($membership->isActive($now));
        self::assertTrue($membership->isInFreeTrial($now));
        self::assertSame(10, $membership->freeTrialDaysLeft($now));
        self::assertSame(1, $membership->freeTrialDaysLeft(new DateTimeImmutable('2026-09-30 09:00:00')), 'The last hours still count as a day left');
        self::assertTrue($membership->keepsFreeTrialDaysOnSubscribe($now));
        self::assertFalse($membership->isEndedFreeTrialOnly($now));
    }

    public function testLastDayOfFreeTrialHasNothingToCarryIntoASubscription(): void
    {
        // Checkout turns whole remaining days into a Stripe trial - under a day means payment right away
        $now = new DateTimeImmutable('2026-09-29 18:00:00');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-09-30 10:00:00'),
            trialEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
        );

        self::assertTrue($membership->isInFreeTrial($now));
        self::assertFalse($membership->keepsFreeTrialDaysOnSubscribe($now));
    }

    public function testEndedFreeTrial(): void
    {
        $now = new DateTimeImmutable('2026-10-05');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-09-30 10:00:00'),
            trialEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
        );

        self::assertFalse($membership->isActive($now));
        self::assertFalse($membership->isInFreeTrial($now));
        self::assertSame(0, $membership->freeTrialDaysLeft($now));
        self::assertTrue($membership->isEndedFreeTrialOnly($now));
    }

    public function testSubscribingDuringTheTrialMakesThePlayerASubscriber(): void
    {
        $now = new DateTimeImmutable('2026-09-24');
        $membership = new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: null,
            billingPeriodEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
            grantedUntil: new DateTimeImmutable('2026-09-30 10:00:00'),
            trialEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
        );

        self::assertTrue($membership->isActive($now));
        self::assertFalse($membership->isInFreeTrial($now));
        self::assertFalse($membership->isEndedFreeTrialOnly(new DateTimeImmutable('2026-12-01')));
    }

    public function testVoucherClaimedDuringTheTrialOutlivesIt(): void
    {
        $afterTrial = new DateTimeImmutable('2026-10-05');
        $membership = new PlayerMembership(
            stripeSubscriptionId: null,
            endsAt: null,
            billingPeriodEndsAt: null,
            grantedUntil: new DateTimeImmutable('2026-12-30 10:00:00'),
            trialEndsAt: new DateTimeImmutable('2026-09-30 10:00:00'),
        );

        self::assertTrue($membership->isActive($afterTrial));
        self::assertFalse($membership->isInFreeTrial($afterTrial));
        self::assertFalse($membership->isEndedFreeTrialOnly($afterTrial));
    }

    public function testPlanSwitchIsOfferedForTheRegularPlans(): void
    {
        $now = new DateTimeImmutable('2026-11-02');

        self::assertSame(MembershipPlan::Yearly, $this->subscribed(MembershipPlan::Monthly)->planSwitchTarget($now));
        self::assertSame(MembershipPlan::Monthly, $this->subscribed(MembershipPlan::Yearly)->planSwitchTarget($now));
    }

    public function testNoPlanSwitchForAnyOtherPriceOrState(): void
    {
        $now = new DateTimeImmutable('2026-11-02');

        // Early adopter and other prices are stored as no plan
        self::assertNull($this->subscribed(null)->planSwitchTarget($now));
        // Cancelled, days left
        self::assertNull($this->subscribed(MembershipPlan::Monthly, endsAt: new DateTimeImmutable('2026-11-29'))->planSwitchTarget($now));
        // A change already booked - even to a price that is no plan here
        self::assertNull($this->subscribed(MembershipPlan::Yearly, scheduledPlan: MembershipPlan::Monthly, scheduledAt: new DateTimeImmutable('2027-05-01'))->planSwitchTarget($now));
        self::assertNull($this->subscribed(MembershipPlan::Yearly, scheduledAt: new DateTimeImmutable('2027-05-01'))->planSwitchTarget($now));
        // Trial or voucher days running on the subscription
        self::assertNull($this->subscribed(MembershipPlan::Monthly, grantedUntil: new DateTimeImmutable('2026-11-10'))->planSwitchTarget($now));
        // An ended grant does not matter any more
        self::assertSame(MembershipPlan::Yearly, $this->subscribed(MembershipPlan::Monthly, grantedUntil: new DateTimeImmutable('2026-10-01'))->planSwitchTarget($now));
    }

    public function testPlanIsReadFromTheStoredLookupKeys(): void
    {
        $row = [
            'stripe_subscription_id' => 'sub_1abc',
            'ends_at' => null,
            'billing_period_ends_at' => '2027-05-01 10:00:00',
            'granted_until' => null,
            'discount_percent' => null,
            'discount_voucher_code' => null,
            'stripe_price_lookup_key' => 'puzzlership_yearly',
            'scheduled_price_lookup_key' => 'puzzlership_monthly',
            'scheduled_price_change_at' => '2027-05-01 10:00:00',
        ];

        $membership = PlayerMembership::fromDatabaseRow($row);
        self::assertSame(MembershipPlan::Yearly, $membership->plan);
        self::assertSame(MembershipPlan::Monthly, $membership->scheduledPlan);
        self::assertEquals(new DateTimeImmutable('2027-05-01 10:00:00'), $membership->scheduledPlanChangeAt);

        $promo = PlayerMembership::fromDatabaseRow(['stripe_price_lookup_key' => 'puzzlership_monthly_promo', 'scheduled_price_lookup_key' => null, 'scheduled_price_change_at' => null] + $row);
        self::assertNull($promo->plan);
        self::assertNull($promo->scheduledPlanChangeAt);
    }

    private function subscribed(
        null|MembershipPlan $plan,
        null|DateTimeImmutable $endsAt = null,
        null|DateTimeImmutable $grantedUntil = null,
        null|MembershipPlan $scheduledPlan = null,
        null|DateTimeImmutable $scheduledAt = null,
    ): PlayerMembership {
        return new PlayerMembership(
            stripeSubscriptionId: 'sub_1abc',
            endsAt: $endsAt,
            billingPeriodEndsAt: $endsAt === null ? new DateTimeImmutable('2026-11-29') : null,
            grantedUntil: $grantedUntil,
            plan: $plan,
            scheduledPlan: $scheduledPlan,
            scheduledPlanChangeAt: $scheduledAt,
        );
    }
}
