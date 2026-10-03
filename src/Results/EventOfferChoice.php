<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

/**
 * One row of the "What will you bring?" picker (GetEventOfferChoices): a published listing of the seller, whether it is
 * marked for this event, and the other marketplace events it is marked for (short names, nearest first).
 */
readonly final class EventOfferChoice
{
    /**
     * @param list<string> $alsoAt
     */
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|float $imageRatio,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public bool $reserved,
        public bool $bringing,
        public array $alsoAt,
    ) {
    }
}
