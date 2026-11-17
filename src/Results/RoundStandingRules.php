<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the standings that count one round ask of its results tools (world-series-rules.md W1-b/W1-c): whether a piece
 * missing from a finished puzzle costs a penalty in one of them (the judge card's and desk's help line), and the names of
 * the standings that score its entrants without a result as no-shows (`as_zero_pieces` - the publish readiness line).
 */
readonly final class RoundStandingRules
{
    /**
     * @param list<string> $noShowStandings
     */
    public function __construct(
        public bool $penalisedMissing = false,
        public array $noShowStandings = [],
    ) {
    }

    /**
     * The additive keys for the round's JSON (absent when off, so every other round's answer keeps its shape).
     *
     * @return array{penalisedMissing?: true, noShowStandings?: list<string>}
     */
    public function roundKeys(bool $withNoShows): array
    {
        $keys = [];

        if ($this->penalisedMissing) {
            $keys['penalisedMissing'] = true;
        }

        if ($withNoShows && $this->noShowStandings !== []) {
            $keys['noShowStandings'] = $this->noShowStandings;
        }

        return $keys;
    }
}
