<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnSubscription;
use SpeedPuzzling\Web\MessageHandler\EnableSavingPaymentMethodOnSubscriptionHandler;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;

final class EnableSavingPaymentMethodOnSubscriptionHandlerTest extends TestCase
{
    public function testLivingSubscriptionGetsOnlyTheSavingSettingChanged(): void
    {
        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->method('retrieve')->willReturn(self::subscription('sub_live', Subscription::STATUS_PAST_DUE, 'off'));
        $subscriptions->expects(self::once())
            ->method('update')
            ->with('sub_live', ['payment_settings' => ['save_default_payment_method' => 'on_subscription']]);

        new EnableSavingPaymentMethodOnSubscriptionHandler($this->client($subscriptions), new NullLogger())(
            new EnableSavingPaymentMethodOnSubscription('sub_live'),
        );
    }

    #[DataProvider('untouched')]
    public function testSubscriptionIsNotUpdated(string $status, string $setting): void
    {
        $subscriptions = $this->createMock(SubscriptionService::class);
        $subscriptions->method('retrieve')->willReturn(self::subscription('sub_x', $status, $setting));
        $subscriptions->expects(self::never())->method('update');

        new EnableSavingPaymentMethodOnSubscriptionHandler($this->client($subscriptions), new NullLogger())(
            new EnableSavingPaymentMethodOnSubscription('sub_x'),
        );
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function untouched(): iterable
    {
        yield 'already on' => [Subscription::STATUS_ACTIVE, 'on_subscription'];
        yield 'canceled' => [Subscription::STATUS_CANCELED, 'off'];
        yield 'incomplete_expired' => [Subscription::STATUS_INCOMPLETE_EXPIRED, 'off'];
    }

    private function client(SubscriptionService $subscriptions): StripeClient
    {
        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            static fn (string $name) => $name === 'subscriptions' ? $subscriptions : null,
        );

        return $stripeClient;
    }

    private static function subscription(string $id, string $status, string $setting): Subscription
    {
        return Subscription::constructFrom(self::subscriptionData($id, $status, $setting));
    }

    /**
     * @return array<string, mixed>
     */
    private static function subscriptionData(string $id, string $status, string $setting): array
    {
        return [
            'id' => $id,
            'object' => 'subscription',
            'status' => $status,
            'payment_settings' => ['save_default_payment_method' => $setting],
        ];
    }
}
