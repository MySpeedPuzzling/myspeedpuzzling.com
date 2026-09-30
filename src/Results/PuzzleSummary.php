<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Public facts behind the "About this puzzle" section and the meta description of a puzzle page
 * (docs/features/seo/implementation-plan-2026-10.md, WS-A). Never Puzzle Insights: those are members-only.
 *
 * Counts are solves (every logged solve, timed or not, as in puzzle_statistics), not puzzlers - the leaderboard
 * counts players and keeps each one's best time, so the two numbers differ by definition. The medians are the
 * ones puzzle_statistics keeps.
 */
readonly final class PuzzleSummary
{
    /**
     * @param list<CompetitionReference> $usedAt publicly visible competitions (by tag or round), oldest first
     */
    public function __construct(
        public int $soloSolvesCount,
        public null|int $medianTimeSolo,
        public null|int $fastestTimeSolo,
        public int $duoSolvesCount,
        public null|int $medianTimeDuo,
        public null|int $fastestTimeDuo,
        public int $teamSolvesCount,
        public null|int $medianTimeTeam,
        public null|int $fastestTimeTeam,
        public array $usedAt,
    ) {
    }

    public static function empty(): self
    {
        return new self(
            soloSolvesCount: 0,
            medianTimeSolo: null,
            fastestTimeSolo: null,
            duoSolvesCount: 0,
            medianTimeDuo: null,
            fastestTimeDuo: null,
            teamSolvesCount: 0,
            medianTimeTeam: null,
            fastestTimeTeam: null,
            usedAt: [],
        );
    }

    /**
     * Solves logged without a time count as solves but give no median - such a puzzle has no solo times yet.
     */
    public function hasSoloTimes(): bool
    {
        return $this->soloSolvesCount > 0 && $this->medianTimeSolo !== null && $this->fastestTimeSolo !== null;
    }

    /**
     * One solo puzzler so far (or all of them share the best time): the median would only repeat the fastest time.
     */
    public function hasSingleSoloTime(): bool
    {
        return $this->hasSoloTimes() && $this->medianTimeSolo === $this->fastestTimeSolo;
    }

    public function hasDuoTimes(): bool
    {
        return $this->duoSolvesCount > 0 && $this->fastestTimeDuo !== null;
    }

    public function hasTeamTimes(): bool
    {
        return $this->teamSolvesCount > 0 && $this->fastestTimeTeam !== null;
    }

    public function hasGroupTimes(): bool
    {
        return $this->hasDuoTimes() || $this->hasTeamTimes();
    }
}
