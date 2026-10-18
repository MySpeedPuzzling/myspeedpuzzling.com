<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What a run of PuzzleTagWriter did: puzzles rewritten, tag rows inserted or changed, tag rows removed.
 */
readonly final class PuzzleTagWriteOutcome
{
    public function __construct(
        public int $puzzles,
        public int $written,
        public int $removed,
    ) {
    }

    public function plus(self $other): self
    {
        return new self($this->puzzles + $other->puzzles, $this->written + $other->written, $this->removed + $other->removed);
    }
}
