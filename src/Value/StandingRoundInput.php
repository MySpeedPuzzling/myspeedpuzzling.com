<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One round of a standing as StandingCalculator::sum() reads it, in the standing's order.
 */
final readonly class StandingRoundInput
{
    /**
     * @param list<StandingEntryInput> $entries
     */
    public function __construct(
        public string $roundId,
        // null = no time limit: an unfinished result of the round cannot score
        public null|int $minutesLimit,
        // A round that does not count yet scores nobody and makes nobody "missing" (A §3.4)
        public bool $counted,
        public array $entries,
        // The round is final: an entrant without a result in it is a no-show (as_zero_pieces, world-series-rules.md
        // §4.2). Public view: always (only published rounds count); organiser view: its results are published.
        public bool $final = true,
        // The pieces an entrant who did not appear would have had to place (as_zero_pieces); null = unknown
        public null|int $noShowPiecesCount = null,
    ) {
    }
}
