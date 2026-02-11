<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\JsonType;
use SpeedPuzzling\Web\Value\SellSwapListSettings;

final class SellSwapListSettingsDoctrineType extends JsonType
{
    public const string NAME = 'sell_swap_list_settings';

    /**
     * @throws InvalidType
     */
    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): null|SellSwapListSettings
    {
        if ($value === null) {
            return null;
        }

        /** @var array{description?: null|string, currency?: null|string, custom_currency?: null|string, shipping_info?: null|string, contact_info?: null|string, shipping_countries?: string[], shipping_cost?: null|string} $jsonData */
        $jsonData = parent::convertToPHPValue($value, $platform);

        return new SellSwapListSettings(
            description: $jsonData['description'] ?? null,
            currency: $jsonData['currency'] ?? null,
            customCurrency: $jsonData['custom_currency'] ?? null,
            shippingInfo: $jsonData['shipping_info'] ?? null,
            contactInfo: $jsonData['contact_info'] ?? null,
            shippingCountries: $jsonData['shipping_countries'] ?? [],
            shippingCost: $jsonData['shipping_cost'] ?? null,
        );
    }

    /**
     * @param null|SellSwapListSettings $value
     * @throws InvalidType
     */
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): null|string
    {
        if ($value === null) {
            return null;
        }

        $data = [
            'description' => $value->description,
            'currency' => $value->currency,
            'custom_currency' => $value->customCurrency,
            'shipping_info' => $value->shippingInfo,
            'contact_info' => $value->contactInfo,
            'shipping_countries' => $value->shippingCountries,
            'shipping_cost' => $value->shippingCost,
        ];

        return parent::convertToDatabaseValue($data, $platform);
    }
}
