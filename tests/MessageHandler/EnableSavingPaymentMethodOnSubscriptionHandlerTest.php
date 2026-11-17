<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnAllSubscriptions;
use SpeedPuzzling\Web\Message\EnableSavingPaymentMethodOnSubscription;
use SpeedPuzzling\Web\MessageHandler\EnableSavingPaymentMethodOnAllSubscriptionsHandler;
use SpeedPuzzling\Web\MessageHandler\EnableSavingPaymentMethodOnSubscriptionHandler;
use Stripe\Collection;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;
use Stripe\Subscription;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

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

    public function testBackfillQueuesOnlyLivingSubscriptionsThatHaveItOff(): void
    {
        $subscriptions = $this->createStub(SubscriptionService::class);
        $subscriptions->method('all')->willReturn(Collection::constructFrom([
            'object' => 'list',
            'has_more' => false,
            'data' => [
                self::subscriptionData('sub_active', Subscription::STATUS_ACTIVE, 'off'),
                self::subscriptionData('sub_trialing', Subscription::STATUS_TRIALING, 'off'),
                self::subscriptionData('sub_done', Subscription::STATUS_ACTIVE, 'on_subscription'),
                self::subscriptionData('sub_canceled', Subscription::STATUS_CANCELED, 'off'),
            ],
        ]));

        $dispatched = new \ArrayObject();
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use ($dispatched): Envelope {
            assert($message instanceof EnableSavingPaymentMethodOnSubscription);
            $dispatched->append($message->stripeSubscriptionId);

            return new Envelope($message);
        });

        $handler = new EnableSavingPaymentMethodOnAllSubscriptionsHandler($this->client($subscriptions), $bus);

        self::assertSame(['sub_active', 'sub_trialing'], $handler(new EnableSavingPaymentMethodOnAllSubscriptions(dryRun: true)));
        self::assertSame([], $dispatched->getArrayCopy(), 'A dry run changes nothing');

        self::assertSame(['sub_active', 'sub_trialing'], $handler(new EnableSavingPaymentMethodOnAllSubscriptions(dryRun: false)));
        self::assertSame(['sub_active', 'sub_trialing'], $dispatched->getArrayCopy());
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
