<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\SellSwapListSettings;

final class EditSellSwapListSettingsFormData
{
    public null|string $description = null;

    public null|string $currency = null;

    public null|string $customCurrency = null;

    public null|string $shippingInfo = null;

    public null|string $contactInfo = null;

    public static function fromSettings(null|SellSwapListSettings $settings): self
    {
        $data = new self();

        if ($settings !== null) {
            $data->description = $settings->description;
            $data->currency = $settings->currency;
            $data->customCurrency = $settings->customCurrency;
            $data->shippingInfo = $settings->shippingInfo;
            $data->contactInfo = $settings->contactInfo;
        }

        return $data;
    }
}
