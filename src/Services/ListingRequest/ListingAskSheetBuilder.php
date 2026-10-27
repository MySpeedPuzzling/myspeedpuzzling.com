<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ListingRequest;

use SpeedPuzzling\Web\Query\GetRequestableListings;
use SpeedPuzzling\Web\Query\GetSellSwapListItems;
use SpeedPuzzling\Web\Results\ListingAskSheet;
use SpeedPuzzling\Web\Results\SellSwapListItemOverview;

/**
 * The listing request sheet (docs/features/marketplace/12-listing-requests.md) from the ticked listing ids: the one rule
 * (GetRequestableListings) decides, grouped by seller in the order ticked. Two statements: the listings, and the buyer's
 * own sell/swap list (what they may offer in exchange) - only when a swap is among them.
 */
readonly final class ListingAskSheetBuilder
{
    public function __construct(
        private GetRequestableListings $getRequestableListings,
        private GetSellSwapListItems $getSellSwapListItems,
    ) {
    }

    /**
     * @param list<string> $listingIds
     */
    public function build(string $buyerId, null|string $buyerCountry, array $listingIds, bool $truncated): ListingAskSheet
    {
        $requestable = $this->getRequestableListings->forListings($buyerId, $listingIds);
        $bySeller = [];
        $notAskable = [];
        $acceptsSwap = false;

        foreach ($requestable as $listingId => $listing) {
            $reason = $listing->skipReason();

            if ($reason !== null) {
                $notAskable[$listingId] = ['listing' => $listing, 'reason' => $reason];
                continue;
            }

            $bySeller[$listing->sellerId][] = $listing;
            $acceptsSwap = $acceptsSwap || $listing->acceptsSwap();
        }

        $ownListings = $acceptsSwap
            ? array_values(array_filter(
                $this->getSellSwapListItems->byPlayerId($buyerId),
                static fn (SellSwapListItemOverview $item): bool => $item->reserved === false,
            ))
            : [];

        return new ListingAskSheet(
            listingsBySeller: $bySeller,
            notAskable: $notAskable,
            unavailableCount: count(array_unique($listingIds)) - count($requestable),
            ownListings: $ownListings,
            buyerCountry: $buyerCountry,
            truncated: $truncated,
        );
    }
}
