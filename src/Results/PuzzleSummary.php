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
     * At most this many round lines in "Used at" - the rest is "and N more" (usedAtMore)
     */
    public const int USED_AT_ROUNDS_LIMIT = 10;

    /**
     * @param list<PuzzleUsedAtLine> $usedAt "Used at" (docs/features/events-page/high-frequency-series.md P24): the
     *     rounds of publicly visible events holding the revealed puzzle, newest first, at most USED_AT_ROUNDS_LIMIT,
     *     then the tags of publicly visible events and series no round line names
     * @param int $usedAtMore round lines beyond the first USED_AT_ROUNDS_LIMIT
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
        public int $usedAtMore = 0,
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
     * The round lines alone - a signed-in player's Details shows the puzzle's tags as badges already
     *
     * @return list<PuzzleUsedAtLine>
     */
    public function usedAtRounds(): array
    {
        return array_values(array_filter($this->usedAt, static fn (PuzzleUsedAtLine $line): bool => $line->isRound()));
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
