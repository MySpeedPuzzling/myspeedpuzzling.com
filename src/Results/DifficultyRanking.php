<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DifficultyRankingDirection;

/**
 * A hardest / easiest list as a page renders it. Members get the ranking with
 * difficulty; everybody else gets forPublic(): the first puzzles only, with
 * every score and tier stripped (difficulty is a members-only insight).
 */
readonly final class DifficultyRanking
{
    /**
     * @param list<DifficultyRankingEntry> $entries in ranking order
     */
    public function __construct(
        public DifficultyRankingDirection $direction,
        public int $ratedPuzzlesCount,
        public array $entries,
        public bool $withDifficulty,
    ) {
    }

    public function forPublic(int $limit): self
    {
        return new self(
            direction: $this->direction,
            ratedPuzzlesCount: $this->ratedPuzzlesCount,
            entries: array_map(
                static fn (DifficultyRankingEntry $entry): DifficultyRankingEntry => $entry->withoutDifficulty(),
                array_slice($this->entries, 0, $limit),
            ),
            withDifficulty: false,
        );
    }
}
