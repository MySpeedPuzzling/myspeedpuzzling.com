<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\StandingEntrant;

/**
 * One entrant of a computed standing (StandingCalculator::sum()).
 */
readonly final class StandingRow
{
    /**
     * @param list<string> $countries
     * @param list<StandingEntrant> $entrants
     * @param array<string, StandingRoundScore> $perRound every round of the standing, in its order
     */
    public function __construct(
        public string $key,
        // null = not ranked (incomplete: a counted round without a score)
        public null|int $rank,
        public bool $complete,
        public string $name,
        public array $countries,
        public array $entrants,
        public int $totalSeconds,
        public int $roundsScored,
        public int $roundsFinished,
        public array $perRound,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'rank' => $this->rank,
            'complete' => $this->complete,
            'name' => $this->name,
            'countries' => $this->countries,
            'entrants' => array_map(static fn (StandingEntrant $entrant): array => $entrant->toArray(), $this->entrants),
            'totalSeconds' => $this->totalSeconds,
            'roundsScored' => $this->roundsScored,
            'roundsFinished' => $this->roundsFinished,
            'perRound' => array_values(array_map(static fn (StandingRoundScore $score): array => $score->toArray(), $this->perRound)),
        ];
    }
}
