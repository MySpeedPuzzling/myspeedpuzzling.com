<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Selected puzzles of the wishlist page (docs/features/collections/bulk-actions.md). Answers a SelectedPuzzlesOutcome.
 */
readonly final class RemovePuzzlesFromWishList
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $playerId,
        public array $puzzleIds,
    ) {
    }
}
