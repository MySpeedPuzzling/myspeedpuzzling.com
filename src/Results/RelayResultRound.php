<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The round a relay team's result is ranked on (docs/features/event-results-import/solo-and-relays.md §2).
 */
readonly final class RelayResultRound
{
    /**
     * @param list<string> $puzzleNames the relay's puzzles, in the order of its results
     */
    public function __construct(
        public string $competitionSlug,
        public string $competitionName,
        public string $roundSlug,
        public int $totalSeconds,
        public array $puzzleNames,
    ) {
    }
}
