<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What "Keep this one" did - for the message the player reads afterwards.
 */
readonly final class DuplicateCopiesDeleted
{
    public function __construct(
        public int $deleted,
        // A deleted copy was a first try, but the kept pair/team result could not take the tag over: somebody of it
        // already has another first try of the puzzle (docs/features/first-try-integrity.md)
        public bool $firstTryLeftOff,
    ) {
    }
}
