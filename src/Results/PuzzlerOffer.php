<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class PuzzlerOffer
{
    public function __construct(
        public string $sellSwapListItemId,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public DateTimeImmutable $addedAt,
        // Seller info
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|string $playerAvatar,
        public null|string $playerCountry,
        // Seller settings (for currency display)
        public null|string $currency,
        public null|string $customCurrency,
    ) {
    }
}
