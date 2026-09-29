<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class PuzzleMarketplaceOffer
{
    public function __construct(
        public float $price,
        public string $currency,
        public PuzzleCondition $condition,
    ) {
    }

    /**
     * The cheapest offer per currency - the "from €5" next to the offers badge, so
     * the prices of the Product structured data are visible on the page. Prices in
     * different currencies are never compared with each other; the currency most
     * offers use comes first (then alphabetically).
     *
     * @param list<self> $offers
     * @return array<string, float> currency => lowest price
     */
    public static function lowestPricePerCurrency(array $offers): array
    {
        $lowest = [];
        $counts = [];

        foreach ($offers as $offer) {
            $lowest[$offer->currency] = min($lowest[$offer->currency] ?? $offer->price, $offer->price);
            $counts[$offer->currency] = ($counts[$offer->currency] ?? 0) + 1;
        }

        uksort(
            $lowest,
            static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b],
        );

        return $lowest;
    }
}
