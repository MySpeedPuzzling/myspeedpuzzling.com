<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A listing on the marketplace. Marketplace at events (docs/features/marketplace/11-events.md):
 * `bringing` - under the event filter, the seller marked it for the chosen event;
 * `bringingTo` - the nearest marketplace event it is marked for while its seller still goes (everyone sees it);
 * `sellerGoesTo` - the nearest marketplace event both the seller and the viewer go to, not marked for it (only that
 * viewer gets it - "Ask to bring it").
 */
readonly final class MarketplaceListingItem
{
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $manufacturerName,
        public string $listingType,
        public null|float $price,
        public string $condition,
        public null|string $comment,
        public bool $reserved,
        public null|string $reservedForPlayerId,
        public null|string $reservedForPlayerName,
        public string $addedAt,
        public string $sellerId,
        public null|string $sellerName,
        public null|string $sellerCode,
        public null|string $sellerAvatar,
        public null|string $sellerCountry,
        public null|string $sellerCurrency,
        public null|string $sellerCustomCurrency,
        public null|string $sellerShippingCost,
        public int $sellerRatingCount = 0,
        public null|float $sellerAverageRating = null,
        public bool $bringing = false,
        public null|MarketplaceEvent $bringingTo = null,
        public null|MarketplaceEvent $sellerGoesTo = null,
    ) {
    }
}
