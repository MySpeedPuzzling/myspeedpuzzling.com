<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\Puzzle;

/**
 * A puzzle's id with every name of it - what PuzzleMergeNames works with, read from the entity (the merge) or from a
 * moderator's read model (the merge review).
 */
readonly final class NamedPuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $name,
        public null|string $nameLanguage,
        public PuzzleNames $alternativeNames,
    ) {
    }

    public static function ofPuzzle(Puzzle $puzzle): self
    {
        return new self($puzzle->id->toString(), $puzzle->name, $puzzle->nameLanguage, $puzzle->alternativeNames());
    }
}
