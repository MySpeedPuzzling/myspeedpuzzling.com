<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class SellSwapListSettings
{
    public function __construct(
        public null|string $description = null,
        public null|string $currency = null,
        public null|string $customCurrency = null,
        public null|string $shippingInfo = null,
        public null|string $contactInfo = null,
        /** @var string[] */
        public array $shippingCountries = [],
        public null|string $shippingCost = null,
    ) {
    }
}
