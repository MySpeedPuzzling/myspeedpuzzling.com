<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Selected puzzles of one collection page (docs/features/collections/bulk-actions.md). Null collection = the system
 * collection. Answers a SelectedPuzzlesOutcome.
 */
readonly final class MovePuzzlesToCollection
{
    /**
     * @param list<string> $puzzleIds
     */
    public function __construct(
        public string $playerId,
        public array $puzzleIds,
        public null|string $sourceCollectionId,
        public null|string $targetCollectionId,
    ) {
    }
}
