<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use SpeedPuzzling\Web\Message\CreateAffiliatePayout;
use SpeedPuzzling\Web\Repository\AffiliatePayoutRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Stripe\Customer;
use Stripe\Invoice;
use Stripe\Service\CustomerService;
use Stripe\Service\InvoiceService;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Stripe webhook and the checkout-success page dispatch CreateAffiliatePayout for the
 * same invoice at the same moment. The per-invoice lock only makes the "payout already
 * exists?" check safe if the payout row is committed before the next handler may look -
 * a lock released while the row is still waiting for the doctrine_transaction flush lets
 * both handlers insert, and the loser dies with a unique violation (production: a 500 on
 * the checkout-success page and on a Stripe webhook).
 */
final class CreateAffiliatePayoutConcurrencyTest extends KernelTestCase
{
    private const string SUBSCRIPTION_ID = 'sub_test_concurrency';
    private const string INVOICE_ID = 'in_test_concurrency';
    private const string CUSTOMER_ID = 'cus_test_concurrency';

    public function testInvoiceLockIsHeldUntilThePayoutIsFlushedAndCommitted(): void
    {
        $container = self::getContainer();
        $container->set(StripeClient::class, $this->stripeClientForReferredSubscriber());

        $lockFactory = $container->get(LockFactory::class);
        $entityManager = $container->get(EntityManagerInterface::class);

        $listener = new class ($lockFactory, 'affiliate-payout-' . self::INVOICE_ID) {
            public null|bool $lockWasFreeDuringFlush = null;

            public function __construct(
                private readonly LockFactory $lockFactory,
                private readonly string $lockKey,
            ) {
            }

            public function postFlush(): void
            {
                // What a concurrent handler for the same invoice would try at this moment
                $concurrentLock = $this->lockFactory->createLock($this->lockKey);
                $this->lockWasFreeDuringFlush = $concurrentLock->acquire(blocking: false);

                if ($this->lockWasFreeDuringFlush) {
                    $concurrentLock->release();
                }
            }
        };
        $entityManager->getEventManager()->addEventListener(Events::postFlush, $listener);

        try {
            $container->get(MessageBusInterface::class)->dispatch(
                new CreateAffiliatePayout(self::SUBSCRIPTION_ID, self::INVOICE_ID),
            );
        } finally {
            $entityManager->getEventManager()->removeEventListener(Events::postFlush, $listener);
        }

        self::assertTrue(
            $container->get(AffiliatePayoutRepository::class)->existsByStripeInvoiceId(self::INVOICE_ID),
            'The payout should have been created',
        );
        self::assertFalse(
            $listener->lockWasFreeDuringFlush,
            'A concurrent handler could take the invoice lock before the payout was committed',
        );

        // ...and it is released once the transaction is done
        $lockAfterwards = $lockFactory->createLock('affiliate-payout-' . self::INVOICE_ID);
        self::assertTrue($lockAfterwards->acquire(blocking: false));
        $lockAfterwards->release();
    }

    private function stripeClientForReferredSubscriber(): StripeClient
    {
        $subscriptionService = $this->createStub(SubscriptionService::class);
        $subscriptionService->method('retrieve')->willReturn(Subscription::constructFrom([
            'id' => self::SUBSCRIPTION_ID,
            'customer' => self::CUSTOMER_ID,
        ]));

        // PLAYER_PRIVATE was referred by PLAYER_REGULAR, who is in the referral program (AffiliateFixture)
        $customerService = $this->createStub(CustomerService::class);
        $customerService->method('retrieve')->willReturn(Customer::constructFrom([
            'id' => self::CUSTOMER_ID,
            'metadata' => ['player_id' => PlayerFixture::PLAYER_PRIVATE],
        ]));

        $invoiceService = $this->createStub(InvoiceService::class);
        $invoiceService->method('retrieve')->willReturn(Invoice::constructFrom([
            'id' => self::INVOICE_ID,
            'amount_paid' => 600,
            'currency' => 'eur',
        ]));

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
