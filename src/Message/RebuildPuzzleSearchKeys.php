<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Builds the search keys of these puzzles again from their names and codes (Puzzle::refreshSearchKeys()) - one batch
 * of myspeedpuzzling:rebuild-puzzle-search-keys, committed on its own.
 */
readonly final class RebuildPuzzleSearchKeys
{
    public function __construct(
        /** @var list<string> */
        public array $puzzleIds,
    ) {
    }
}
