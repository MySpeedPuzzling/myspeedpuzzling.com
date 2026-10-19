<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One puzzle the player is ranked on, as close calls need it (GetCloseCalls): their best solo time, their place on the
 * puzzle's solo leaderboard (= GetRanking::allForPlayer()) and the times of the places above them - names nobody.
 */
readonly final class CloseCallCandidate
{
    /**
     * @param null|int $nextPlaceSeconds the best time of the slowest player still faster than you - tie it and you
     *                                   move up at least one place; null on first place
     * @param array<int, int> $milestoneSeconds 1-based leaderboard position (LeaderboardPosition::RANK_MILESTONES above
     *                                          your place only) => the best time there; tie it and you reach that place
     */
    public function __construct(
        public string $puzzleId,
        public int $bestSeconds,
        public int $rank,
        public null|int $nextPlaceSeconds,
        public array $milestoneSeconds,
    ) {
    }
}
