<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PlayerMembership;

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
}
