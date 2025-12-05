<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditSellSwapListSettings
{
    public function __construct(
        public string $playerId,
        public null|string $description,
        public null|string $currency,
        public null|string $customCurrency,
        public null|string $shippingInfo,
        public null|string $contactInfo,
    ) {
    }
}
