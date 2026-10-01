<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Where the subject's best time stands on the puzzle's unfiltered leaderboard of its category
 * (solo / pairs / teams) - the same rank the leaderboard shows for its row.
 */
readonly final class PuzzleResultStanding
{
    public function __construct(
        // Ties share the rank: 1 + the number of subjects strictly faster
        public int $rank,
        public int $total,
        public int $subjectTime,
        public int $leaderTime,
        // The closest strictly faster best time, null when the subject leads
        public null|int $closestFasterTime,
    ) {
    }

    public function gapToLeader(): null|int
    {
        $gap = $this->subjectTime - $this->leaderTime;

        return $gap > 0 ? $gap : null;
    }

    /**
     * Only when the closest faster time is not the leader's - the leader gap already says that one
     */
    public function gapToNextFaster(): null|int
    {
        if ($this->closestFasterTime === null || $this->closestFasterTime === $this->leaderTime) {
            return null;
        }

        return $this->subjectTime - $this->closestFasterTime;
    }
}
