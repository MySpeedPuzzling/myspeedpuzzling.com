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
}
