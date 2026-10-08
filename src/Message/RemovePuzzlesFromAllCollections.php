<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Selected puzzles of the unsolved page, taken out of every collection of the player (docs/features/collections/bulk-actions.md).
 * Answers a SelectedPuzzlesOutcome.
 */
readonly final class RemovePuzzlesFromAllCollections
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
