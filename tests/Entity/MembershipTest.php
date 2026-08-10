<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Events\MembershipSubscriptionRenewed;
use Stripe\Subscription;

final class MembershipTest extends TestCase
{
    private function createPlayer(): Player
    {
        return new Player(
            id: Uuid::uuid7(),
            code: 'testplayer',
            userId: 'auth0|test',
            email: 'test@example.com',
            name: 'Test Player',
            registeredAt: new DateTimeImmutable(),
        );
    }

    private function createMembership(
        null|string $stripeSubscriptionId = null,
        null|DateTimeImmutable $billingPeriodEndsAt = null,
        null|DateTimeImmutable $endsAt = null,
        null|DateTimeImmutable $grantedUntil = null,
    ): Membership {
        $membership = new Membership(
            id: Uuid::uuid7(),
            player: $this->createPlayer(),
            createdAt: new DateTimeImmutable('-30 days'),
            stripeSubscriptionId: $stripeSubscriptionId,
            billingPeriodEndsAt: $billingPeriodEndsAt,
            endsAt: $endsAt,
            grantedUntil: $grantedUntil,
        );

        $membership->popEvents();

        return $membership;
    }

    /**
     * Simulates the exact Stripe webhook flow during normal subscription renewal.
     *
     * Stripe sends customer.subscription.updated with new period_end BEFORE the invoice
     * is finalized (~1 hour gap). The billingPeriodEndsAt must be updated immediately.
     *
     * Example Stripe webhook payload (customer.subscription.updated):
     * {
     *   "type": "customer.subscription.updated",
     *   "data": {
     *     "object": {
     *       "id": "sub_1abc",
     *       "status": "active",
     *       "cancel_at_period_end": false,
     *       "customer": "cus_xyz",
     *       "items": { "data": [{ "current_period_end": 1745366400 }] }
     *     }
     *   }
     * }
     */
    public function testRenewalUpdatesBillingPeriodWithoutPaymentConfirmation(): void
    {
        $oldPeriodEnd = new DateTimeImmutable('2026-03-23');
        $newPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23 00:05:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $oldPeriodEnd,
        );

        // customer.subscription.updated fires (before invoice finalization)
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now, isPaymentConfirmed: false);

        self::assertNull($membership->endsAt);
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($newPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());
        self::assertEmpty($membership->popEvents());
    }

    /**
     * After the invoice is finalized and paid, invoice.payment_succeeded fires.
     * This should emit the MembershipSubscriptionRenewed event.
     *
     * Example Stripe webhook payload (invoice.payment_succeeded):
     * {
     *   "type": "invoice.payment_succeeded",
     *   "data": {
     *     "object": {
     *       "id": "in_1abc",
     *       "status": "paid",
     *       "parent": {
     *         "subscription_details": { "subscription": "sub_1abc" }
     *       }
     *     }
     *   }
     * }
     */
    public function testRenewalEmitsEventOnPaymentConfirmation(): void
    {
        $oldPeriodEnd = new DateTimeImmutable('2026-03-23');
        $newPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23 01:05:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $oldPeriodEnd,
        );

        // Step 1: customer.subscription.updated (before payment)
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now, isPaymentConfirmed: false);
        $membership->popEvents();

        // Step 2: invoice.payment_succeeded
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now, isPaymentConfirmed: true);

        $events = $membership->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipSubscriptionRenewed::class, $events[0]);
    }

    /**
     * When the invoice payment fails, Stripe sets subscription to past_due.
     * Membership should be paused immediately to prevent free access.
     *
     * Example Stripe webhook payload (customer.subscription.updated with past_due):
     * {
     *   "type": "customer.subscription.updated",
     *   "data": {
     *     "object": {
     *       "id": "sub_1abc",
     *       "status": "past_due",
     *       "cancel_at_period_end": false,
     *       "customer": "cus_xyz",
     *       "items": { "data": [{ "current_period_end": 1745366400 }] }
     *     }
     *   }
     * }
     */
    public function testPastDuePausesMembership(): void
    {
        $newPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23 01:30:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $newPeriodEnd,
        );

        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_PAST_DUE, $now);

        self::assertEquals($now, $membership->endsAt);
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($newPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());
    }

    /**
     * Full renewal flow: subscription updated → payment fails → Stripe retries → payment succeeds.
     * Membership should be paused during past_due and resume on successful retry.
     */
    public function testRecoveryFromPastDueToActive(): void
    {
        $oldPeriodEnd = new DateTimeImmutable('2026-03-23');
        $newPeriodEnd = new DateTimeImmutable('2026-04-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $oldPeriodEnd,
        );

        // Step 1: Renewal starts - subscription updated (active, before payment)
        $now1 = new DateTimeImmutable('2026-03-23 00:05:00');
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now1);
        self::assertNull($membership->endsAt);
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($newPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());

        // Step 2: Payment fails - subscription goes past_due
        $now2 = new DateTimeImmutable('2026-03-23 01:30:00');
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_PAST_DUE, $now2);
        self::assertEquals($now2, $membership->endsAt);

        // Step 3: Stripe retries 3 days later - payment succeeds
        $now3 = new DateTimeImmutable('2026-03-26 10:00:00');
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now3, isPaymentConfirmed: true);
        self::assertNull($membership->endsAt);
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($newPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());

        $events = $membership->popEvents();
        self::assertNotEmpty($events);
    }

    /**
     * Grant survives subscription activation.
     * When a player with a manual grant subscribes, the grant should not be lost.
     */
    public function testGrantedUntilSurvivesSubscriptionActivation(): void
    {
        $grantedUntil = new DateTimeImmutable('2026-06-01');
        $billingPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23');

        $membership = $this->createMembership(
            grantedUntil: $grantedUntil,
        );

        // Player subscribes - subscription created with active status
        $membership->updateStripeSubscription('sub_new', $billingPeriodEnd, Subscription::STATUS_ACTIVE, $now);

        // grantedUntil must survive
        self::assertEquals($grantedUntil, $membership->grantedUntil);
        // Subscription state set correctly
        self::assertNull($membership->endsAt);
        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($billingPeriodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());
        self::assertSame('sub_new', $membership->stripeSubscriptionId);
    }

    /**
     * Grant survives subscription cancellation.
     * If the subscription is cancelled but the grant is still valid, membership stays active.
     */
    public function testGrantedUntilSurvivesSubscriptionCancellation(): void
    {
        $grantedUntil = new DateTimeImmutable('2026-06-01');
        $billingPeriodEnd = new DateTimeImmutable('2026-04-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $billingPeriodEnd,
            grantedUntil: $grantedUntil,
        );

        // Subscription cancelled
        $membership->cancel($billingPeriodEnd);

        // endsAt set to billing period end, billingPeriodEndsAt cleared
        self::assertEquals($billingPeriodEnd, $membership->endsAt);
        self::assertNull($membership->billingPeriodEndsAt);
        // grantedUntil preserved
        self::assertEquals($grantedUntil, $membership->grantedUntil);
    }

    /**
     * Grant survives past_due status.
     * Even if payment fails, the grant should still be valid.
     */
    public function testGrantedUntilSurvivesPastDueStatus(): void
    {
        $grantedUntil = new DateTimeImmutable('2026-06-01');
        $billingPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23 01:30:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $billingPeriodEnd,
            grantedUntil: $grantedUntil,
        );

        $membership->updateStripeSubscription('sub_1abc', $billingPeriodEnd, Subscription::STATUS_PAST_DUE, $now);

        // Subscription paused
        self::assertEquals($now, $membership->endsAt);
        // grantedUntil preserved
        self::assertEquals($grantedUntil, $membership->grantedUntil);
    }

    /**
     * Grant survives subscription deletion (customer.subscription.deleted).
     */
    public function testGrantedUntilSurvivesSubscriptionDeletion(): void
    {
        $grantedUntil = new DateTimeImmutable('2026-06-01');
        $now = new DateTimeImmutable('2026-03-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: new DateTimeImmutable('2026-04-23'),
            grantedUntil: $grantedUntil,
        );

        // customer.subscription.deleted handler calls cancel($now)
        $membership->cancel($now);

        self::assertEquals($now, $membership->endsAt);
        self::assertEquals($grantedUntil, $membership->grantedUntil);
    }

    /**
     * billingPeriodEndsAt should not be downgraded when the same period is received.
     */
    public function testBillingPeriodNotDowngraded(): void
    {
        $periodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $periodEnd,
        );

        // Same period end received again (e.g., duplicate webhook)
        $membership->updateStripeSubscription('sub_1abc', $periodEnd, Subscription::STATUS_ACTIVE, $now);

        self::assertNotNull($membership->billingPeriodEndsAt);
        self::assertEquals($periodEnd->getTimestamp(), $membership->billingPeriodEndsAt->getTimestamp());
    }

    /**
     * A cancel-at-period-end reaches us as two webhooks a whole billing period apart:
     * customer.subscription.updated with cancel_at_period_end=true when the player clicks cancel,
     * then customer.subscription.deleted once the period actually runs out. Both call cancel(),
     * and the player must only ever be told about that cancellation once.
     */
    public function testCancelAtPeriodEndFollowedByDeletionEmitsSingleEvent(): void
    {
        $billingPeriodEnd = new DateTimeImmutable('2026-06-12 07:11:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $billingPeriodEnd,
        );

        // Player clicks cancel - membership keeps running until the period ends
        $membership->cancel($billingPeriodEnd);
        $events = $membership->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipSubscriptionCancelled::class, $events[0]);

        // A month later Stripe deletes the subscription and the handler calls cancel(now).
        // `now` is a couple of minutes past the period end - that must not look like a new cancellation.
        $membership->cancel(new DateTimeImmutable('2026-06-12 07:13:00'));

        self::assertEmpty($membership->popEvents());
    }

    /**
     * Re-subscribing clears endsAt, so a later cancellation is a genuinely new one and must notify again.
     */
    public function testCancellationAfterResubscribeEmitsEventAgain(): void
    {
        $firstPeriodEnd = new DateTimeImmutable('2026-04-23');
        $secondPeriodEnd = new DateTimeImmutable('2026-06-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $firstPeriodEnd,
        );

        $membership->cancel($firstPeriodEnd);
        self::assertCount(1, $membership->popEvents());

        // Player comes back with a new subscription
        $membership->updateStripeSubscription(
            'sub_2xyz',
            $secondPeriodEnd,
            Subscription::STATUS_ACTIVE,
            new DateTimeImmutable('2026-05-23'),
        );
        $membership->popEvents();
        self::assertNull($membership->endsAt);

        $membership->cancel($secondPeriodEnd);

        $events = $membership->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipSubscriptionCancelled::class, $events[0]);
    }

    /**
     * A subscription that stops paying gets endsAt set by updateStripeSubscription() without any
     * notification - that path only pauses access while Stripe retries. When Stripe finally gives up and
     * deletes the subscription, the player still has to be told the membership is gone.
     */
    public function testUnpaidSubscriptionStillNotifiesOnDeletion(): void
    {
        $billingPeriodEnd = new DateTimeImmutable('2026-04-23');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $billingPeriodEnd,
        );

        // Payment failed - access is paused while Stripe retries, no e-mail about a cancellation yet
        $membership->updateStripeSubscription(
            'sub_1abc',
            $billingPeriodEnd,
            Subscription::STATUS_PAST_DUE,
            new DateTimeImmutable('2026-03-23 01:30:00'),
        );
        self::assertEmpty($membership->popEvents());

        // Stripe exhausted its retries and deleted the subscription
        $membership->cancel(new DateTimeImmutable('2026-04-06 09:00:00'));

        $events = $membership->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipSubscriptionCancelled::class, $events[0]);
    }

    /**
     * Stripe keeps reporting the paid period on a cancelled subscription, so a late or out-of-order
     * webhook must not push endsAt back into the future and revive a membership that already ended.
     */
    public function testCancelNeverExtendsAnAlreadyEndedMembership(): void
    {
        $terminatedAt = new DateTimeImmutable('2026-08-10 12:50:00');
        $paidPeriodEnd = new DateTimeImmutable('2026-08-29 07:24:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $paidPeriodEnd,
        );

        // Dispute lost - membership ends right now, mid paid period
        $membership->cancel($terminatedAt);
        $membership->popEvents();

        // A stray customer.subscription.updated arrives afterwards carrying status=canceled, and
        // updateStripeSubscription() hands cancel() the still-future end of the paid period
        $membership->updateStripeSubscription(
            'sub_1abc',
            $paidPeriodEnd,
            Subscription::STATUS_CANCELED,
            new DateTimeImmutable('2026-08-10 12:50:05'),
        );

        self::assertEquals($terminatedAt, $membership->endsAt);
    }

    /**
     * Renewal event should not be emitted twice for the same period.
     */
    public function testNoDoubleRenewalEvent(): void
    {
        $oldPeriodEnd = new DateTimeImmutable('2026-03-23');
        $newPeriodEnd = new DateTimeImmutable('2026-04-23');
        $now = new DateTimeImmutable('2026-03-23 01:05:00');

        $membership = $this->createMembership(
            stripeSubscriptionId: 'sub_1abc',
            billingPeriodEndsAt: $oldPeriodEnd,
        );

        // First payment confirmation
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now, isPaymentConfirmed: true);
        $events1 = $membership->popEvents();
        self::assertCount(1, $events1);

        // Duplicate payment webhook with same period
        $membership->updateStripeSubscription('sub_1abc', $newPeriodEnd, Subscription::STATUS_ACTIVE, $now, isPaymentConfirmed: true);
        $events2 = $membership->popEvents();
        self::assertEmpty($events2);
    }
}
