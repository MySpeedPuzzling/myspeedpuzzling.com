<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Message\SyncPlanSwitchPortalConfiguration;
use SpeedPuzzling\Web\MessageHandler\SyncPlanSwitchPortalConfigurationHandler;
use Stripe\BillingPortal\Configuration;
use Stripe\Collection;
use Stripe\Service\BillingPortal\BillingPortalServiceFactory;
use Stripe\Service\BillingPortal\ConfigurationService;
use Stripe\Service\PriceService;
use Stripe\StripeClient;

final class SyncPlanSwitchPortalConfigurationHandlerTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $created = [];

    /** @var list<array{string, array<string, mixed>}> */
    private array $updated = [];

    public function testCreatesTheConfigurationCopiedFromTheDefaultPlusTheSwitch(): void
    {
        $result = ($this->handler([$this->defaultConfiguration()]))(new SyncPlanSwitchPortalConfiguration());

        self::assertTrue($result->created);
        self::assertSame('bpc_created', $result->configurationId);
        self::assertCount(1, $this->created);
        self::assertSame([], $this->updated);

        $payload = $this->created[0];
        self::assertSame(['msp_purpose' => 'plan_switch'], $payload['metadata']);
        self::assertSame('https://myspeedpuzzling.com/en/membership', $payload['default_return_url']);
        self::assertSame(['enabled' => false], $payload['login_page']);
        self::assertArrayNotHasKey('business_profile', $payload);

        /** @var array<string, mixed> $features */
        $features = $payload['features'];

        // Everything the default allows, as it allows it
        self::assertSame(['enabled' => true, 'allowed_updates' => ['name', 'email', 'address', 'phone']], $features['customer_update']);
        self::assertSame(['enabled' => true], $features['invoice_history']);
        self::assertSame(['enabled' => true], $features['payment_method_update']);
        self::assertSame([
            'enabled' => true,
            'mode' => 'at_period_end',
            'proration_behavior' => 'none',
            'cancellation_reason' => ['enabled' => true, 'options' => ['too_expensive', 'switched_service', 'unused', 'other']],
        ], $features['subscription_cancel']);

        // Plus the switch between exactly the two regular prices
        self::assertSame([
            'enabled' => true,
            'default_allowed_updates' => ['price'],
            'products' => [['product' => 'prod_regular', 'prices' => ['price_monthly', 'price_yearly'], 'adjustable_quantity' => ['enabled' => false]]],
            'proration_behavior' => 'always_invoice',
            'schedule_at_period_end' => ['conditions' => [['type' => 'shortening_interval']]],
        ], $features['subscription_update']);
    }

    public function testUpdatesTheExistingConfigurationInsteadOfCreatingAnother(): void
    {
        $existing = Configuration::constructFrom([
            'id' => 'bpc_existing',
            'object' => 'billing_portal.configuration',
            'is_default' => false,
            'active' => true,
            'metadata' => ['msp_purpose' => 'plan_switch'],
        ]);

        $result = ($this->handler([$this->defaultConfiguration(), $existing]))(new SyncPlanSwitchPortalConfiguration());

        self::assertFalse($result->created);
        self::assertSame('bpc_existing', $result->configurationId);
        self::assertSame([], $this->created);
        self::assertCount(1, $this->updated);
        self::assertSame('bpc_existing', $this->updated[0][0]);
    }

    public function testDryRunChangesNothing(): void
    {
        $result = ($this->handler([$this->defaultConfiguration()]))(new SyncPlanSwitchPortalConfiguration(dryRun: true));

        self::assertTrue($result->dryRun);
        self::assertTrue($result->created);
        self::assertNull($result->configurationId);
        self::assertSame([], $this->created);
        self::assertSame([], $this->updated);
        self::assertArrayHasKey('features', $result->payload);
    }

    public function testRefusesPricesOfTwoProducts(): void
    {
        $this->expectException(\RuntimeException::class);

        try {
            ($this->handler([$this->defaultConfiguration()], yearlyProduct: 'prod_other'))(new SyncPlanSwitchPortalConfiguration());
        } finally {
            self::assertSame([], $this->created);
        }
    }

    private function defaultConfiguration(): Configuration
    {
        return Configuration::constructFrom([
            'id' => 'bpc_default',
            'object' => 'billing_portal.configuration',
            'is_default' => true,
            'active' => true,
            'metadata' => [],
            'default_return_url' => 'https://myspeedpuzzling.com/en/membership',
            'business_profile' => ['headline' => null, 'privacy_policy_url' => null, 'terms_of_service_url' => null],
            'features' => [
                'customer_update' => ['allowed_updates' => ['name', 'email', 'address', 'phone'], 'enabled' => true],
                'invoice_history' => ['enabled' => true],
                'payment_method_update' => ['enabled' => true, 'payment_method_configuration' => null],
                'subscription_cancel' => [
                    'cancellation_reason' => ['enabled' => true, 'feedback_options' => [], 'options' => ['too_expensive', 'switched_service', 'unused', 'other']],
                    'enabled' => true,
                    'mode' => 'at_period_end',
                    'proration_behavior' => 'none',
                ],
                'subscription_update' => ['enabled' => false],
            ],
        ]);
    }

    /**
     * @param list<Configuration> $configurations
     */
    private function handler(array $configurations, string $yearlyProduct = 'prod_regular'): SyncPlanSwitchPortalConfigurationHandler
    {
        $configurationService = $this->createStub(ConfigurationService::class);
        $configurationService->method('all')->willReturn(Collection::constructFrom([
            'object' => 'list',
            'data' => array_map(static fn (Configuration $c): array => $c->toArray(), $configurations),
        ]));
        $configurationService->method('create')->willReturnCallback(function (array $params): Configuration {
            /** @var array<string, mixed> $params */
            $this->created[] = $params;

            return Configuration::constructFrom(['id' => 'bpc_created', 'object' => 'billing_portal.configuration']);
        });
        $configurationService->method('update')->willReturnCallback(function (string $id, array $params): Configuration {
            /** @var array<string, mixed> $params */
            $this->updated[] = [$id, $params];

            return Configuration::constructFrom(['id' => $id, 'object' => 'billing_portal.configuration']);
        });

        $billingPortal = $this->createStub(BillingPortalServiceFactory::class);
        $billingPortal->method('__get')->willReturnCallback(fn (string $name) => $name === 'configurations' ? $configurationService : null);

        $prices = $this->createStub(PriceService::class);
        $prices->method('all')->willReturnCallback(static function (array $params) use ($yearlyProduct): Collection {
            /** @var array{lookup_keys: list<string>} $params */
            $yearly = $params['lookup_keys'][0] === 'puzzlership_yearly';

            return Collection::constructFrom([
                'object' => 'list',
                'data' => [[
                    'id' => $yearly ? 'price_yearly' : 'price_monthly',
                    'object' => 'price',
                    'lookup_key' => $params['lookup_keys'][0],
                    'product' => $yearly ? $yearlyProduct : 'prod_regular',
                ]],
            ]);
        });

        $stripeClient = $this->createStub(StripeClient::class);
        $stripeClient->method('__get')->willReturnCallback(
            fn (string $name) => match ($name) {
                'billingPortal' => $billingPortal,
                'prices' => $prices,
                default => null,
            },
        );

        return new SyncPlanSwitchPortalConfigurationHandler($stripeClient);
    }
}
