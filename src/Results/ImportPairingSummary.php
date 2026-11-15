<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Where the pairing of an import's distinct puzzles stands, as its page says it
 * (docs/features/data-import/pairing.md "The summary"): by what is left for the player to do.
 */
readonly final class ImportPairingSummary
{
    public function __construct(
        /** Distinct puzzles of the import */
        public int $puzzles,
        /** Decided to be a catalogue puzzle - by the rules ("found"), remembered, or by the player */
        public int $found,
        /** One catalogue puzzle by a weaker rule, waiting for the player's look */
        public int $probably,
        /** Several candidates or similar names, waiting for the player's choice */
        public int $choose,
        /** Nothing in the catalogue - or kept as unlisted */
        public int $notFound,
        /** The player leaves them out */
        public int $leftOut,
        /** The similar-name search has not come to them yet */
        public int $searching,
        /** The pairing came to rest after its last run */
        public bool $paired,
    ) {
    }
}
