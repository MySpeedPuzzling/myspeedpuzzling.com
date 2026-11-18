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
     * @param list<string> $outsideKeys entrants in the round with an entry of another division (or none) - a standing of
     *     one division (forDivision()) neither scores them here nor makes them a no-show
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
        public array $outsideKeys = [],
    ) {
    }

    /**
     * The round as a standing of one division reads it (real events L2a, shared-puzzles-and-divisions.md D7): only the
     * entries carrying that division; an entrant whose entry here has another division (or none) is "not in the round"
     * for the standing - counted only in the rounds where it is in the division, never a no-show where it played in
     * another one. The round counts once something of the division is recorded in it (organiser: a result or did not
     * start; public: a ranked result) - a round where nobody of the division is (yet) makes nobody missing.
     */
    public function forDivision(string $divisionId): self
    {
        $divisionId = strtolower($divisionId);
        $inside = [];
        $outside = [];

        foreach ($this->entries as $entry) {
            if ($entry->divisionId !== null && strtolower($entry->divisionId) === $divisionId) {
                $inside[] = $entry;
            } else {
                $outside[$entry->key] = true;
            }
        }

        foreach ($inside as $entry) {
            unset($outside[$entry->key]);
        }

        return new self(
            roundId: $this->roundId,
            minutesLimit: $this->minutesLimit,
            counted: $this->counted && array_any($inside, static fn (StandingEntryInput $entry): bool => $entry->result->isNone() === false),
            entries: $inside,
            final: $this->final,
            noShowPiecesCount: $this->noShowPiecesCount,
            outsideKeys: array_map(strval(...), array_keys($outside)),
        );
    }
}
