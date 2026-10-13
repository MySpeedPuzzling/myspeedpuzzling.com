<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PlayerResultsCriteria;

/**
 * The figures of a player's Overview (GetPlayerOverviewNumbers, docs/features/navigation README §4.7, PROF-03): the
 * personal best per piece-count preset and the headline numbers - free figures only (decision 69).
 *
 * The numbers are what the Results page lists for the same viewer (PlayerOverviewNumbersParityTest): the puzzles of each
 * tab ("By puzzle") and every result ("Every result" - the three tabs together).
 */
readonly final class PlayerOverviewNumbers
{
    /**
     * @param list<PersonalBest> $personalBests in the presets' order, only presets with a timed solo result
     */
    public function __construct(
        public int $soloPuzzles,
        public int $pairPuzzles,
        public int $teamPuzzles,
        public int $soloResults,
        public int $pairResults,
        public int $teamResults,
        public array $personalBests,
    ) {
    }

    public function totalResults(): int
    {
        return $this->soloResults + $this->pairResults + $this->teamResults;
    }

    /**
     * @return list<string>
     */
    public function bestPuzzleIds(): array
    {
        return array_values(array_unique(array_map(static fn (PersonalBest $best): string => $best->puzzleId, $this->personalBests)));
    }

    /**
     * The tab the Results page opens without a category: Solo, else the first tab with results (PlayerResultsBuilder) -
     * its links leave the category out
     */
    public function defaultCategory(): string
    {
        return match (true) {
            $this->soloResults > 0 => PlayerResultsCriteria::CATEGORY_SOLO,
            $this->pairResults > 0 => PlayerResultsCriteria::CATEGORY_DUO,
            $this->teamResults > 0 => PlayerResultsCriteria::CATEGORY_GROUP,
            default => PlayerResultsCriteria::CATEGORY_SOLO,
        };
    }
}
