<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class MarketplaceListingItem
{
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $piecesCount,
        public null|string $puzzleImage,
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
    ) {
    }
}
