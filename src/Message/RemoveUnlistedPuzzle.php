<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Remove" on an unlisted puzzle's page (docs/features/data-import/unlisted-puzzles.md): the player's own unlisted
 * puzzle goes with its unlisted results and marks. Nothing that became a real result or item is touched.
 */
readonly final class RemoveUnlistedPuzzle
{
    public function __construct(
        public string $unlistedPuzzleId,
        public string $playerId,
    ) {
    }
}
