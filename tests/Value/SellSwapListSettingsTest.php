<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\SellSwapListSettings;

final class SellSwapListSettingsTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $settings = new SellSwapListSettings();

        self::assertNull($settings->description);
        self::assertNull($settings->currency);
        self::assertNull($settings->customCurrency);
        self::assertNull($settings->shippingInfo);
        self::assertNull($settings->contactInfo);
        self::assertSame([], $settings->shippingCountries);
        self::assertNull($settings->shippingCost);
    }

    public function testAllFieldsPopulated(): void
    {
        $settings = new SellSwapListSettings(
            description: 'Test description',
            currency: 'EUR',
            customCurrency: null,
            shippingInfo: 'Free shipping',
            contactInfo: 'email@test.com',
            shippingCountries: ['cz', 'sk', 'de'],
            shippingCost: '€5 domestic, €8 EU',
        );

        self::assertSame('Test description', $settings->description);
        self::assertSame('EUR', $settings->currency);
        self::assertSame(['cz', 'sk', 'de'], $settings->shippingCountries);
        self::assertSame('€5 domestic, €8 EU', $settings->shippingCost);
    }

    public function testBackwardsCompatibilityWithOldData(): void
    {
        // Simulates creating from old JSON data that lacks new fields
        $settings = new SellSwapListSettings(
            description: 'Old description',
            currency: 'CZK',
            customCurrency: null,
            shippingInfo: 'Ships everywhere',
            contactInfo: 'contact@test.com',
        );

        self::assertSame([], $settings->shippingCountries);
        self::assertNull($settings->shippingCost);
    }

    public function testEmptyShippingCountries(): void
    {
        $settings = new SellSwapListSettings(
            shippingCountries: [],
        );

        self::assertSame([], $settings->shippingCountries);
    }
}
