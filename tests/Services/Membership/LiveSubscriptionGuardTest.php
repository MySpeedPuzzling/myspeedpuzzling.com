<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Membership;

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

final class LiveSubscriptionGuardTest extends TestCase
{
    private const string SUBSCRIPTION = 'sub_test_live_guard';
    private const string INVOICE_URL = 'https://invoice.stripe.com/i/acct_test/live_test';

    #[DataProvider('unpaidStatuses')]
    public function testUnpaidSubscriptionSendsThePlayerToItsOpenInvoice(string $status): void
    {
        try {
            $this->guard($status, ['id' => 'in_test', 'object' => 'invoice', 'status' => 'open', 'hosted_invoice_url' => self::INVOICE_URL])
                ->assertNoLiveSubscription(self::SUBSCRIPTION);
            self::fail('A new checkout must not start next to an unpaid subscription');
        } catch (MembershipPaymentPending $pending) {
            self::assertSame(self::INVOICE_URL, $pending->hostedInvoiceUrl);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unpaidStatuses(): iterable
    {
        yield 'past_due' => [Subscription::STATUS_PAST_DUE];
        yield 'unpaid' => [Subscription::STATUS_UNPAID];
        yield 'incomplete' => [Subscription::STATUS_INCOMPLETE];
    }

    public function testUnpaidSubscriptionWithoutAnOpenInvoiceGoesToTheBillingPortal(): void
    {
        $this->expectException(PlayerAlreadyHaveMembership::class);

        $this->guard(Subscription::STATUS_PAST_DUE, ['id' => 'in_test', 'object' => 'invoice', 'status' => 'paid', 'hosted_invoice_url' => self::INVOICE_URL])
            ->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    #[DataProvider('runningStatuses')]
    public function testRunningSubscriptionGoesToTheBillingPortal(string $status): void
    {
        $this->expectException(PlayerAlreadyHaveMembership::class);

        $this->guard($status, null)->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function runningStatuses(): iterable
    {
        yield 'active' => [Subscription::STATUS_ACTIVE];
        yield 'trialing' => [Subscription::STATUS_TRIALING];
    }

    #[DataProvider('endedStatuses')]
    public function testEndedOrPausedSubscriptionAllowsANewCheckout(string $status): void
    {
        // No exception = a new checkout may start; the mock's retrieve() expectation is the assertion
        $this->guard($status, null)->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function endedStatuses(): iterable
    {
        yield 'canceled' => [Subscription::STATUS_CANCELED];
        yield 'incomplete_expired' => [Subscription::STATUS_INCOMPLETE_EXPIRED];
        yield 'paused' => [Subscription::STATUS_PAUSED];
    }

    public function testStripeErrorAllowsANewCheckoutAsBeforeTheGuard(): void
    {
        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('retrieve')
            ->willThrowException(InvalidRequestException::factory('No such subscription', 404));

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            static fn (string $name) => $name === 'subscriptions' ? $subscriptions : null,
        );

        // No exception = the checkout goes on
        new LiveSubscriptionGuard($stripeClient, new NullLogger())->assertNoLiveSubscription(self::SUBSCRIPTION);
    }

    /**
     * @param null|array<string, mixed> $latestInvoice
     */
    private function guard(string $status, null|array $latestInvoice): LiveSubscriptionGuard
    {
        $subscription = Subscription::constructFrom([
            'id' => self::SUBSCRIPTION,
            'object' => 'subscription',
            'status' => $status,
            'latest_invoice' => $latestInvoice,
        ]);

        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->expects(self::once())
            ->method('retrieve')
            ->with(self::SUBSCRIPTION, ['expand' => ['latest_invoice']])
            ->willReturn($subscription);

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            static fn (string $name) => $name === 'subscriptions' ? $subscriptions : null,
        );

        return new LiveSubscriptionGuard($stripeClient, new NullLogger());
    }
}
