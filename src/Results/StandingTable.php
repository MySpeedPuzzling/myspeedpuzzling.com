<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A computed standing (GetStandingResults): its rounds as columns and its entrants in order.
 */
readonly final class StandingTable
{
    /**
     * @param list<StandingRoundColumn> $rounds the standing's rounds that are rounds of its event and category, in its order
     * @param list<StandingRow> $rows
     */
    public function __construct(
        public CompetitionStandingOverview $standing,
        public array $rounds,
        public array $rows,
        // Rounds of the stored list that are not rounds of the event and category any more (a category changed) - skipped
        public int $skippedRounds,
        // The organiser's view: computed over every round, published or not
        public bool $includesUnpublished,
    ) {
    }

    public function roundsCounted(): int
    {
        return count(array_filter($this->rounds, static fn (StandingRoundColumn $round): bool => $round->counted));
    }

    public function roundsTotal(): int
    {
        return count($this->rounds);
    }

    /**
     * Not final yet: a round does not count yet, or (organiser's view) a round's results are not published.
     */
    public function isProvisional(): bool
    {
        foreach ($this->rounds as $round) {
            if ($round->counted === false || ($this->includesUnpublished && $round->published === false)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<StandingRoundColumn> rounds without a time limit where somebody did not finish - those results cannot
     *     score
     */
    public function roundsWithoutLimit(): array
    {
        return array_values(array_filter($this->rounds, static fn (StandingRoundColumn $round): bool => $round->hasUnfinished && $round->minutesLimit === null));
    }

    /**
     * @return list<StandingRoundColumn> rounds where somebody did not finish, whose pieces are unknown, while a penalty per
     *     missing piece is set
     */
    public function roundsWithoutPieces(): array
    {
        if ($this->standing->rules->penaltySecondsPerPiece === 0) {
            return [];
        }

        return array_values(array_filter($this->rounds, static fn (StandingRoundColumn $round): bool => $round->hasUnfinished && $round->piecesUnknown));
    }

    /**
     * The organiser JSON (official_results_standing_state, A-round-formats.md §6.4).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'standing' => [
                ...$this->standing->toArray(),
                'provisional' => $this->isProvisional(),
                'includesUnpublished' => $this->includesUnpublished,
                'roundsCounted' => $this->roundsCounted(),
                'roundsTotal' => $this->roundsTotal(),
            ],
            'rounds' => array_map(static fn (StandingRoundColumn $round): array => $round->toArray(), $this->rounds),
            'rows' => array_map(static fn (StandingRow $row): array => $row->toArray(), $this->rows),
        ];
    }
}
