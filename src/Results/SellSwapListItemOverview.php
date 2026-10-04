<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use SpeedPuzzling\Web\Value\PuzzleNames;

readonly final class SellSwapListItemOverview
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        // The stored search keys - every name, every code, folded - that the list's search filter reads
        public null|string $searchNames,
        public null|string $searchCodes,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|float $imageRatio,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public DateTimeImmutable $addedAt,
        public bool $reserved,
        public null|string $reservedForPlayerId,
        public null|string $reservedForPlayerName,
        public bool $publishedOnMarketplace = true,
    ) {
    }
}
