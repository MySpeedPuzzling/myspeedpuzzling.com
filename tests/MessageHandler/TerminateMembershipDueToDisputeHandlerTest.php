<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Membership;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Events\MembershipSubscriptionCancelled;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\TerminateMembershipDueToDispute;
use SpeedPuzzling\Web\MessageHandler\TerminateMembershipDueToDisputeHandler;
use SpeedPuzzling\Web\Repository\MembershipRepository;
use Stripe\Charge;
use Stripe\Dispute;
use Stripe\Invoice;
use Stripe\Service\ChargeService;
use Stripe\Service\DisputeService;
use Stripe\Service\InvoiceService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class TerminateMembershipDueToDisputeHandlerTest extends TestCase
{
    private const string DISPUTE_ID = 'du_1U2sMZ';
    private const string CHARGE_ID = 'py_3TyRsM';
    private const string INVOICE_ID = 'in_1TyRs8';
    private const string SUBSCRIPTION_ID = 'sub_1TnZZ0';

    /**
     * The real world case: a SEPA direct debit bounced for insufficient funds. Such disputes arrive
     * already lost - there is nothing to contest, the money is gone.
     */
    public function testLostDisputeTerminatesMembershipAndCancelsSubscription(): void
    {
        $membership = $this->createMembership();

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($this->createSubscription(Subscription::STATUS_ACTIVE));
        $subscriptionService->expects(self::once())
            ->method('cancel')
            ->with(self::SUBSCRIPTION_ID);

        $now = new DateTimeImmutable('2026-08-10 12:50:00');

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_LOST),
            membershipRepository: $this->createMembershipRepositoryReturning($membership),
            subscriptionService: $subscriptionService,
            now: $now,
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));

        self::assertEquals($now, $membership->endsAt);
        self::assertNull($membership->billingPeriodEndsAt);

        $events = $membership->popEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(MembershipSubscriptionCancelled::class, $events[0]);
    }

    /**
     * A membership cancelled at period end keeps running until that date, so the player is still a member
     * for the days covered by the money that just went back to their bank. It has to end now too.
     */
    public function testLostDisputeCutsShortAMembershipCancelledAtPeriodEnd(): void
    {
        $membership = $this->createMembership();
        $membership->cancel(new DateTimeImmutable('2026-08-29 07:24:00'));
        $membership->popEvents();

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($this->createSubscription(Subscription::STATUS_ACTIVE));
        $subscriptionService->expects(self::once())->method('cancel');

        $now = new DateTimeImmutable('2026-08-10 12:50:00');

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_LOST),
            membershipRepository: $this->createMembershipRepositoryReturning($membership),
            subscriptionService: $subscriptionService,
            now: $now,
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));

        self::assertEquals($now, $membership->endsAt);
    }

    /**
     * A dispute we won, or one the bank withdrew, costs us nothing and must leave the player alone.
     */
    public function testDisputeClosedWithoutLosingLeavesMembershipAlone(): void
    {
        $membership = $this->createMembership();

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::never())->method('cancel');

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_WON),
            membershipRepository: $this->createMembershipRepositoryReturning($membership),
            subscriptionService: $subscriptionService,
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));

        self::assertNull($membership->endsAt);
        self::assertEmpty($membership->popEvents());
    }

    /**
     * Stripe redelivers a webhook whenever our endpoint fails, so the same lost dispute can arrive twice.
     * The repeat must not terminate again and must not send a second cancellation e-mail.
     */
    public function testRedeliveryOfTheSameDisputeDoesNotCancelTwice(): void
    {
        $endsAt = new DateTimeImmutable('2026-08-10 12:50:00');
        $membership = $this->createMembership();
        $membership->cancel($endsAt);
        $membership->popEvents();

        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($this->createSubscription(Subscription::STATUS_CANCELED));
        $subscriptionService->expects(self::never())->method('cancel');

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_LOST),
            membershipRepository: $this->createMembershipRepositoryReturning($membership),
            subscriptionService: $subscriptionService,
            now: new DateTimeImmutable('2026-08-10 12:50:02'),
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));

        self::assertEquals($endsAt, $membership->endsAt);
        self::assertEmpty($membership->popEvents());
    }

    /**
     * A disputed one-off payment has no invoice and therefore no subscription to act on.
     */
    public function testDisputeOnChargeWithoutInvoiceIsIgnored(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->expects(self::never())->method('cancel');

        $membershipRepository = $this->createMock(MembershipRepository::class);
        $membershipRepository->expects(self::never())->method('getByStripeSubscriptionId');

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_LOST),
            membershipRepository: $membershipRepository,
            subscriptionService: $subscriptionService,
            charge: Charge::constructFrom(['id' => self::CHARGE_ID, 'invoice' => null]),
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));
    }

    /**
     * The player may have re-subscribed, leaving the disputed charge on a subscription no membership
     * points at anymore. That subscription still bills the mandate that just bounced, so it has to go.
     */
    public function testSubscriptionIsCancelledEvenWithoutAMatchingMembership(): void
    {
        $subscriptionService = $this->createMock(SubscriptionService::class);
        $subscriptionService->method('retrieve')
            ->willReturn($this->createSubscription(Subscription::STATUS_ACTIVE));
        $subscriptionService->expects(self::once())
            ->method('cancel')
            ->with(self::SUBSCRIPTION_ID);

        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->willThrowException(new MembershipNotFound());

        $handler = $this->createHandler(
            dispute: $this->createDispute(Dispute::STATUS_LOST),
            membershipRepository: $membershipRepository,
            subscriptionService: $subscriptionService,
        );

        $handler(new TerminateMembershipDueToDispute(self::DISPUTE_ID));
    }

    private function createMembership(): Membership
    {
        $player = new Player(
            id: Uuid::uuid7(),
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
            stripeSubscriptionId: self::SUBSCRIPTION_ID,
            billingPeriodEndsAt: new DateTimeImmutable('+19 days'),
        );

        $membership->popEvents();

        return $membership;
    }

    private function createDispute(string $status): Dispute
    {
        return Dispute::constructFrom([
            'id' => self::DISPUTE_ID,
            'status' => $status,
            'reason' => Dispute::REASON_INSUFFICIENT_FUNDS,
            'charge' => self::CHARGE_ID,
        ]);
    }

    private function createSubscription(string $status): Subscription
    {
        return Subscription::constructFrom([
            'id' => self::SUBSCRIPTION_ID,
            'status' => $status,
        ]);
    }

    private function createMembershipRepositoryReturning(Membership $membership): MembershipRepository
    {
        $membershipRepository = $this->createStub(MembershipRepository::class);
        $membershipRepository->method('getByStripeSubscriptionId')
            ->willReturn($membership);

        return $membershipRepository;
    }

    private function createHandler(
        Dispute $dispute,
        MembershipRepository $membershipRepository,
        SubscriptionService $subscriptionService,
        null|Charge $charge = null,
        null|DateTimeImmutable $now = null,
    ): TerminateMembershipDueToDisputeHandler {
        $disputeService = $this->createStub(DisputeService::class);
        $disputeService->method('retrieve')->willReturn($dispute);

        $chargeService = $this->createStub(ChargeService::class);
        $chargeService->method('retrieve')->willReturn(
            $charge ?? Charge::constructFrom([
                'id' => self::CHARGE_ID,
                'invoice' => self::INVOICE_ID,
            ]),
        );

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('retrieve')->willReturn(
            Invoice::constructFrom([
                'id' => self::INVOICE_ID,
                'parent' => [
                    'type' => 'subscription_details',
                    'subscription_details' => ['subscription' => self::SUBSCRIPTION_ID],
                ],
            ]),
        );

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'disputes' => $disputeService,
                'charges' => $chargeService,
                'invoices' => $invoiceService,
                'subscriptions' => $subscriptionService,
                default => null,
            },
        );

        return new TerminateMembershipDueToDisputeHandler(
            stripeClient: $stripeClient,
            membershipRepository: $membershipRepository,
            clock: new MockClock($now ?? new DateTimeImmutable('2026-08-10 12:50:00')),
            lockFactory: new LockFactory(new InMemoryStore()),
            logger: new NullLogger(),
        );
    }
}
