<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Membership;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Exceptions\MembershipPaymentPending;
use SpeedPuzzling\Web\Exceptions\PlayerAlreadyHaveMembership;
use SpeedPuzzling\Web\Services\Membership\LiveSubscriptionGuard;
use Stripe\Exception\InvalidRequestException;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Clock\MockClock;

final class LiveSubscriptionGuardTest extends TestCase
{
    private const string SUBSCRIPTION = 'sub_test_live_guard';
    private const string INVOICE_URL = 'https://invoice.stripe.com/i/acct_test/live_test';
    private const string NOW = '2026-11-17 12:00:00';

    public function testRetryPeriodOfTheCurrentBillingPeriodLeadsToTheOpenInvoice(): void
    {
        try {
            $this->guard(Subscription::STATUS_PAST_DUE, '2026-12-16 10:00:00', 'open')
                ->assertNoLiveSubscription(self::SUBSCRIPTION);
            self::fail('A new checkout must not start next to a subscription Stripe still retries');
        } catch (MembershipPaymentPending $pending) {
            self::assertSame(self::INVOICE_URL, $pending->hostedInvoiceUrl);
        }
    }

    public function testComingBackAfterTheBillingPeriodGetsANewCheckout(): void
    {
        // Half a year later - never an old invoice for months the player did not use
        $this->guard(Subscription::STATUS_PAST_DUE, '2026-06-16 10:00:00', 'open')
            ->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    public function testPastDueWithoutAnOpenInvoiceGetsANewCheckout(): void
    {
        $this->guard(Subscription::STATUS_PAST_DUE, '2026-12-16 10:00:00', 'paid')
            ->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    #[DataProvider('runningStatuses')]
    public function testRunningSubscriptionGoesToTheBillingPortal(string $status): void
    {
        $this->expectException(PlayerAlreadyHaveMembership::class);

        $this->guard($status, '2026-12-16 10:00:00', null)->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runningStatuses(): iterable
    {
        yield 'active' => [Subscription::STATUS_ACTIVE];
        yield 'trialing' => [Subscription::STATUS_TRIALING];
    }

    #[DataProvider('checkoutAsBeforeStatuses')]
    public function testOtherStatusesGetANewCheckoutAsBefore(string $status): void
    {
        // No exception = the checkout goes on; the mock's retrieve() expectation is the assertion
        $this->guard($status, '2026-12-16 10:00:00', 'open')->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function checkoutAsBeforeStatuses(): iterable
    {
        yield 'canceled' => [Subscription::STATUS_CANCELED];
        yield 'incomplete_expired' => [Subscription::STATUS_INCOMPLETE_EXPIRED];
        yield 'unpaid' => [Subscription::STATUS_UNPAID];
        yield 'incomplete' => [Subscription::STATUS_INCOMPLETE];
        yield 'paused' => [Subscription::STATUS_PAUSED];
    }

    public function testStripeErrorGetsANewCheckoutAsBefore(): void
    {
        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('retrieve')
            ->willThrowException(InvalidRequestException::factory('No such subscription', 404));

        $this->guardWith($subscriptions)->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    private function guard(string $status, string $periodEnd, null|string $invoiceStatus): LiveSubscriptionGuard
    {
        $subscription = Subscription::constructFrom([
            'id' => self::SUBSCRIPTION,
            'object' => 'subscription',
            'status' => $status,
            'items' => [
                'object' => 'list',
                'data' => [[
                    'id' => 'si_test',
                    'object' => 'subscription_item',
                    'current_period_end' => new DateTimeImmutable($periodEnd)->getTimestamp(),
                ]],
            ],
            'latest_invoice' => $invoiceStatus === null ? null : [
                'id' => 'in_test',
                'object' => 'invoice',
                'status' => $invoiceStatus,
                'hosted_invoice_url' => self::INVOICE_URL,
            ],
        ]);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('retrieve')
            ->with(self::SUBSCRIPTION, ['expand' => ['latest_invoice']])
            ->willReturn($subscription);

        return $this->guardWith($subscriptions);
    }

    private function guardWith(SubscriptionService $subscriptions): LiveSubscriptionGuard
    {
        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            static fn (string $name) => $name === 'subscriptions' ? $subscriptions : null,
        );

        return new LiveSubscriptionGuard($stripeClient, new NullLogger(), new MockClock(self::NOW));
    }
}
