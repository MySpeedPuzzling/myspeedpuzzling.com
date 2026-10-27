<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * One puzzle of the buyer's own sell/swap list offered in exchange - `listingId` null once it left their list.
 */
final readonly class ListingRequestOfferOverview
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public ListingType $listingType,
        public PuzzleCondition $condition,
        public null|string $listingId,
    ) {
    }
}
