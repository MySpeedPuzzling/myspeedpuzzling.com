<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class SellSwapListItemOverview
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $puzzleIdentificationNumber,
        public null|string $ean,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public DateTimeImmutable $addedAt,
        public bool $reserved,
        public bool $publishedOnMarketplace = true,
    ) {
    }
}
