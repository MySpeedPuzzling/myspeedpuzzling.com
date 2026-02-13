<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class AddPuzzleToSellSwapList
{
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public bool $publishedOnMarketplace = true,
    ) {
    }
}
