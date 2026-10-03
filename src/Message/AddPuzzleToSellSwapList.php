<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;

readonly final class AddPuzzleToSellSwapList
{
    /**
     * @param null|list<string> $eventIds The listing form's "I'm bringing it to" (docs/features/marketplace/11-events.md):
     *     null = leave the event links alone (the API and every other caller), a list = exactly these among the
     *     marketplace events the seller goes to now - links to past events stay
     */
    public function __construct(
        public string $playerId,
        public string $puzzleId,
        public ListingType $listingType,
        public null|float $price,
        public PuzzleCondition $condition,
        public null|string $comment,
        public bool $publishedOnMarketplace = true,
        public null|array $eventIds = null,
    ) {
    }
}
