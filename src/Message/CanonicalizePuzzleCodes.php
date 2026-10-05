<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Writes these puzzles' codes in their canonical form where that is a format-only change (PuzzleCodesCleanup) - one
 * batch of myspeedpuzzling:canonicalize-puzzle-codes --write, committed on its own.
 */
readonly final class CanonicalizePuzzleCodes
{
    public function __construct(
        /** @var list<string> */
        public array $puzzleIds,
    ) {
    }
}
