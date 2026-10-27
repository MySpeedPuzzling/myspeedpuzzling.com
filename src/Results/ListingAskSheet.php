<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ListingAskSkipReason;

/**
 * The listing request sheet (ListingAskSheetBuilder): the ticked listings that can be asked for, grouped by seller,
 * those that cannot with their reason, and the buyer's own listings they may offer in exchange.
 */
final readonly class ListingAskSheet
{
    /**
     * @param array<string, list<RequestableListing>> $listingsBySeller seller id => askable listings
     * @param array<string, array{listing: RequestableListing, reason: ListingAskSkipReason}> $notAskable listing id => why not
     * @param list<SellSwapListItemOverview> $ownListings the buyer's own listings - what they may offer in exchange
     */
    public function __construct(
        public array $listingsBySeller,
        public array $notAskable,
        public int $unavailableCount,
        public array $ownListings,
        public null|string $buyerCountry,
        public bool $truncated,
    ) {
    }

    public function askedListingCount(): int
    {
        return array_sum(array_map(count(...), $this->listingsBySeller));
    }

    public function sellerCount(): int
    {
        return count($this->listingsBySeller);
    }

    /**
     * @param list<RequestableListing> $listings
     */
    public function seller(array $listings): RequestableListing
    {
        return $listings[0];
    }

    /**
     * Prices of the group's listings that have one, summed per currency - never across currencies.
     *
     * @param list<RequestableListing> $listings
     * @return array<string, float> currency label ('' = none set) => sum
     */
    public function totals(array $listings): array
    {
        $totals = [];

        foreach ($listings as $listing) {
            if ($listing->hasPrice()) {
                $key = $listing->currency ?? '';
                $totals[$key] = ($totals[$key] ?? 0.0) + (float) $listing->price;
            }
        }

        return $totals;
    }

    /**
     * @param list<RequestableListing> $listings
     */
    public function acceptsSwap(array $listings): bool
    {
        foreach ($listings as $listing) {
            if ($listing->acceptsSwap()) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Ships to you" (true), "Not shipped to your country" (false), or nothing to say (null: the buyer has no country,
     * or the seller lists no countries).
     *
     * @param list<RequestableListing> $listings
     */
    public function shipsToBuyer(array $listings): null|bool
    {
        $countries = $listings[0]->shippingCountries;

        if ($this->buyerCountry === null || $countries === []) {
            return null;
        }

        return in_array(strtolower($this->buyerCountry), array_map(strtolower(...), $countries), true);
    }
}
