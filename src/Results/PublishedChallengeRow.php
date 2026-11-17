<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One row of a challenge's ranking on the public round page (real events W4): the round's entry, its place in that
 * challenge (ChallengeRanking - null = unranked: not finished, or no splits recorded) and the puzzle as the entry's chip
 * (null = no per-puzzle record).
 */
readonly final class PublishedChallengeRow
{
    public function __construct(
        public PublishedRoundEntry $entry,
        public null|int $rank,
        public null|PublishedEntryPuzzle $chip,
    ) {
    }

    /**
     * The challenge's own time - its split minus the previous puzzle's
     */
    public function seconds(): null|int
    {
        return $this->chip !== null && $this->chip->done ? $this->chip->duration : null;
    }
}
