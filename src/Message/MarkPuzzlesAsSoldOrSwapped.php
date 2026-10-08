<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Selected listings of the sell/swap page, sold or swapped to nobody named (docs/features/collections/bulk-actions.md).
 * Answers a SelectedPuzzlesOutcome.
 */
readonly final class MarkPuzzlesAsSoldOrSwapped
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
