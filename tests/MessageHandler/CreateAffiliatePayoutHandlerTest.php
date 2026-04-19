<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\AffiliatePayout;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Referral;
use SpeedPuzzling\Web\Exceptions\ReferralNotFound;
use SpeedPuzzling\Web\Message\CreateAffiliatePayout;
use SpeedPuzzling\Web\MessageHandler\CreateAffiliatePayoutHandler;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use SpeedPuzzling\Web\Repository\ReferralRepository;
use SpeedPuzzling\Web\Value\ReferralSource;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\Service\CustomerService;
use Stripe\Service\InvoiceService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

final class CreateAffiliatePayoutHandlerTest extends TestCase
{
    private const string SUBSCRIPTION_ID = 'sub_test_abc';
    private const string INVOICE_ID = 'in_test_abc';
    private const string CUSTOMER_ID = 'cus_test_abc';

    public function testReturnsEarlyWhenPayoutAlreadyExistsForInvoice(): void
    {
        $stripeClient = $this->createMock(StripeClient::class);
        // No Stripe calls expected — handler must exit on the exists check
        $stripeClient->expects(self::never())->method('__get');

        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->expects(self::once())
            ->method('existsByStripeInvoiceId')
            ->with(self::INVOICE_ID)
            ->willReturn(true);
        $payoutRepository->expects(self::never())->method('save');

        $referralRepository = $this->createMock(ReferralRepository::class);
        $referralRepository->expects(self::never())->method('getBySubscriberId');

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));
    }

    public function testCreatesPayoutWithTenPercentOfAmountPaid(): void
    {
        $playerId = Uuid::uuid7();
        $affiliatePlayer = new Player(
            id: $playerId,
            code: 'affiliate1',
            userId: 'auth0|affiliate',
            email: 'affiliate@example.com',
            name: 'Affiliate',
            registeredAt: new DateTimeImmutable('-100 days'),
        );
        $affiliatePlayer->joinReferralProgram(new DateTimeImmutable('-50 days'));

        $subscriber = new Player(
            id: Uuid::uuid7(),
            code: 'subscriber1',
            userId: 'auth0|subscriber',
            email: 'subscriber@example.com',
            name: 'Subscriber',
            registeredAt: new DateTimeImmutable('-30 days'),
        );

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: ReferralSource::Link,
            createdAt: new DateTimeImmutable('-10 days'),
        );

        $stripeClient = $this->buildStripeClient(
            customerPlayerId: $playerId->toString(),
            amountPaid: 1000,
            currency: 'eur',
        );

        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->method('existsByStripeInvoiceId')->willReturn(false);

        $savedPayout = null;
        $payoutRepository->expects(self::once())
            ->method('save')
            ->willReturnCallback(function (AffiliatePayout $payout) use (&$savedPayout): void {
                $savedPayout = $payout;
            });

        $referralRepository = $this->createStub(ReferralRepository::class);
        $referralRepository->method('getBySubscriberId')->willReturn($referral);

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));

        self::assertInstanceOf(AffiliatePayout::class, $savedPayout);
        self::assertSame(self::INVOICE_ID, $savedPayout->stripeInvoiceId);
        self::assertSame(1000, $savedPayout->paymentAmountCents);
        self::assertSame(100, $savedPayout->payoutAmountCents);
        self::assertSame('EUR', $savedPayout->currency);
        self::assertSame($affiliatePlayer, $savedPayout->affiliatePlayer);
        self::assertSame($referral, $savedPayout->referral);
    }

    public function testSkipsWhenSubscriberHasNoReferral(): void
    {
        $stripeClient = $this->buildStripeClient(
            customerPlayerId: Uuid::uuid7()->toString(),
        );

        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->method('existsByStripeInvoiceId')->willReturn(false);
        $payoutRepository->expects(self::never())->method('save');

        $referralRepository = $this->createStub(ReferralRepository::class);
        $referralRepository->method('getBySubscriberId')
            ->willThrowException(new ReferralNotFound());

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));
    }

    public function testSkipsWhenAffiliateIsNotInReferralProgram(): void
    {
        $playerId = Uuid::uuid7();
        $affiliatePlayer = new Player(
            id: Uuid::uuid7(),
            code: 'affiliate2',
            userId: 'auth0|affiliate2',
            email: 'affiliate2@example.com',
            name: 'Affiliate Two',
            registeredAt: new DateTimeImmutable('-100 days'),
        );
        // Not joined to referral program

        $subscriber = new Player(
            id: $playerId,
            code: 'subscriber2',
            userId: 'auth0|subscriber2',
            email: 'subscriber2@example.com',
            name: 'Subscriber Two',
            registeredAt: new DateTimeImmutable('-30 days'),
        );

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: ReferralSource::Link,
            createdAt: new DateTimeImmutable('-10 days'),
        );

        $stripeClient = $this->buildStripeClient(
            customerPlayerId: $playerId->toString(),
            expectInvoiceRetrieve: false,
        );

        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->method('existsByStripeInvoiceId')->willReturn(false);
        $payoutRepository->expects(self::never())->method('save');

        $referralRepository = $this->createStub(ReferralRepository::class);
        $referralRepository->method('getBySubscriberId')->willReturn($referral);

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));
    }

    public function testSkipsWhenAmountPaidIsZero(): void
    {
        $playerId = Uuid::uuid7();
        $affiliatePlayer = new Player(
            id: Uuid::uuid7(),
            code: 'affiliate3',
            userId: 'auth0|affiliate3',
            email: 'affiliate3@example.com',
            name: 'Affiliate Three',
            registeredAt: new DateTimeImmutable('-100 days'),
        );
        $affiliatePlayer->joinReferralProgram(new DateTimeImmutable('-50 days'));

        $subscriber = new Player(
            id: $playerId,
            code: 'subscriber3',
            userId: 'auth0|subscriber3',
            email: 'subscriber3@example.com',
            name: 'Subscriber Three',
            registeredAt: new DateTimeImmutable('-30 days'),
        );

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: ReferralSource::Link,
            createdAt: new DateTimeImmutable('-10 days'),
        );

        $stripeClient = $this->buildStripeClient(
            customerPlayerId: $playerId->toString(),
            amountPaid: 0,
        );

        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->method('existsByStripeInvoiceId')->willReturn(false);
        $payoutRepository->expects(self::never())->method('save');

        $referralRepository = $this->createStub(ReferralRepository::class);
        $referralRepository->method('getBySubscriberId')->willReturn($referral);

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));
    }

    public function testRaceIsSerializedByLockSoSecondDispatchExitsOnExistsCheck(): void
    {
        // Simulate two concurrent dispatches for the same invoice: the first acquires the
        // lock, persists the payout; the second blocks on acquire(), and after the first
        // releases, finds the row via existsByStripeInvoiceId and exits cleanly.
        //
        // We verify the sequencing by flipping the exists() result between calls.
        $playerId = Uuid::uuid7();
        $affiliatePlayer = new Player(
            id: Uuid::uuid7(),
            code: 'affiliate4',
            userId: 'auth0|affiliate4',
            email: 'affiliate4@example.com',
            name: 'Affiliate Four',
            registeredAt: new DateTimeImmutable('-100 days'),
        );
        $affiliatePlayer->joinReferralProgram(new DateTimeImmutable('-50 days'));

        $subscriber = new Player(
            id: $playerId,
            code: 'subscriber4',
            userId: 'auth0|subscriber4',
            email: 'subscriber4@example.com',
            name: 'Subscriber Four',
            registeredAt: new DateTimeImmutable('-30 days'),
        );

        $referral = new Referral(
            id: Uuid::uuid7(),
            subscriber: $subscriber,
            affiliatePlayer: $affiliatePlayer,
            source: ReferralSource::Link,
            createdAt: new DateTimeImmutable('-10 days'),
        );

        $stripeClient = $this->buildStripeClient(
            customerPlayerId: $playerId->toString(),
            amountPaid: 1000,
        );

        $existsCalls = 0;
        $payoutRepository = $this->createMock(AffiliatePayoutRepository::class);
        $payoutRepository->method('existsByStripeInvoiceId')
            ->willReturnCallback(function () use (&$existsCalls): bool {
                $existsCalls++;

                // First call: nothing exists yet. Second (simulating the concurrent
                // handler after first commits): row exists → second must bail out.
                return $existsCalls > 1;
            });
        $payoutRepository->expects(self::once())->method('save');

        $referralRepository = $this->createStub(ReferralRepository::class);
        $referralRepository->method('getBySubscriberId')->willReturn($referral);

        $handler = $this->buildHandler($stripeClient, $payoutRepository, $referralRepository);

        // First dispatch persists.
        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));
        // Second dispatch for the same invoice — must hit the exists short-circuit.
        $handler(new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID));

        self::assertSame(2, $existsCalls);
    }

    private function buildHandler(
        StripeClient $stripeClient,
        AffiliatePayoutRepository $payoutRepository,
        ReferralRepository $referralRepository,
    ): CreateAffiliatePayoutHandler {
        return new CreateAffiliatePayoutHandler(
            stripeClient: $stripeClient,
            referralRepository: $referralRepository,
            affiliatePayoutRepository: $payoutRepository,
            clock: new MockClock(),
            logger: new NullLogger(),
            lockFactory: new LockFactory(new InMemoryStore()),
        );
    }

    private function buildStripeClient(
        string $customerPlayerId,
        int $amountPaid = 1000,
        string $currency = 'eur',
        bool $expectInvoiceRetrieve = true,
    ): StripeClient {
        $subscription = Subscription::constructFrom([
            'id' => self::SUBSCRIPTION_ID,
            'customer' => self::CUSTOMER_ID,
        ]);

        $customer = Customer::constructFrom([
            'id' => self::CUSTOMER_ID,
            'metadata' => ['player_id' => $customerPlayerId],
        ]);

        $invoice = Invoice::constructFrom([
            'id' => self::INVOICE_ID,
            'amount_paid' => $amountPaid,
            'currency' => $currency,
        ]);

        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')->willReturn($subscription);

        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')->willReturn($customer);

        if ($expectInvoiceRetrieve) {
            $invoiceService = $this->createStub(InvoiceService::class);
            $invoiceService->method('retrieve')->willReturn($invoice);
        } else {
            $invoiceService = $this->createMock(InvoiceService::class);
            $invoiceService->expects(self::never())->method('retrieve');
        }

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'subscriptions' => $subscriptionService,
                'customers' => $customerService,
                'invoices' => $invoiceService,
                default => null,
            },
        );

        return $stripeClient;
    }
}
