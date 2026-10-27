<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ListingAskSkipReason;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * A listing as the buyer may ask for it (GetRequestableListings). A private seller the buyer may not see is shown by
 * code only - never by name or picture.
 */
final readonly class RequestableListing
{
    /**
     * @param list<string> $shippingCountries
     */
    public function __construct(
        public string $listingId,
        public string $sellerId,
        public null|string $sellerName,
        public string $sellerCode,
        public null|string $sellerAvatar,
        public null|string $sellerCountry,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $currency,
        public null|string $shippingCost,
        public array $shippingCountries,
        public bool $own,
        public bool $reservedForSomebodyElse,
        public bool $alreadyAsked,
    ) {
    }

    public function sellerDisplayName(): string
    {
        return $this->sellerName ?? '#' . strtoupper($this->sellerCode);
    }

    /**
     * Why it cannot be asked for - null = it can.
     */
    public function skipReason(): null|ListingAskSkipReason
    {
        return match (true) {
            $this->own => ListingAskSkipReason::Own,
            $this->reservedForSomebodyElse => ListingAskSkipReason::Reserved,
            $this->alreadyAsked => ListingAskSkipReason::AlreadyAsked,
            default => null,
        };
    }

    /**
     * Swaps are offered something in exchange
     */
    public function acceptsSwap(): bool
    {
        return $this->listingType === ListingType::Swap || $this->listingType === ListingType::Both;
    }

    public function hasPrice(): bool
    {
        return $this->price !== null && $this->price > 0
            && ($this->listingType === ListingType::Sell || $this->listingType === ListingType::Both);
    }
}
