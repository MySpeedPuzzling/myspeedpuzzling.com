<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;

readonly final class SoldSwappedItemOverview
{
    public function __construct(
        public string $soldSwappedItemId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $puzzleIdentificationNumber,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public ListingType $listingType,
        public null|float $price,
        public null|string $buyerPlayerId,
        public null|string $buyerPlayerName,
        public null|string $buyerPlayerCode,
        public null|string $buyerName,
        public DateTimeImmutable $soldAt,
    ) {
    }
}
