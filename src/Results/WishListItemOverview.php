<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleNames;

readonly final class WishListItemOverview
{
    public function __construct(
        public string $wishListItemId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public null|string $puzzleIdentificationNumber,
        public null|string $ean,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|float $imageRatio,
        public bool $removeOnCollectionAdd,
        public DateTimeImmutable $addedAt,
    ) {
    }
}
