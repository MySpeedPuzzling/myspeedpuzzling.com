<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class SellSwapListSettings
{
    public function __construct(
        public null|string $description,
        public null|string $currency,
        public null|string $customCurrency,
        public null|string $shippingInfo,
        public null|string $contactInfo,
    ) {
    }
}
