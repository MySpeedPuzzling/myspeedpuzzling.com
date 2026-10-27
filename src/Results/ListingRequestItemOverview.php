<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ListingRequestItemStatus;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * One listing of a listing request as asked (type, price, currency and condition copied then) and how the listing is now
 * (null when it left the list).
 */
final readonly class ListingRequestItemOverview
{
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public ListingRequestItemStatus $status,
        public ListingType $listingType,
        public null|float $price,
        public null|string $currency,
        public PuzzleCondition $condition,
        public bool $reservedByRequest,
        public null|string $listingId,
        public null|float $currentPrice,
        // The listing is reserved right now for the request's buyer / for somebody else
        public bool $reservedForBuyer,
        public bool $reservedForSomebodyElse,
    ) {
    }

    public function hasPrice(): bool
    {
        return $this->price !== null && $this->price > 0
            && ($this->listingType === ListingType::Sell || $this->listingType === ListingType::Both);
    }

    public function priceChanged(): bool
    {
        return $this->listingId !== null && $this->currentPrice !== $this->price;
    }
}
