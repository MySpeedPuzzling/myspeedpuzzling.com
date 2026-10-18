<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One player's history on one puzzle, as shown next to a collection item in
 * the "My times" display mode. Same semantics as the picker card: solveCountAny
 * counts every solving time of mine (solo, duo / team rows I own, team rows I
 * took part in); fastest / latest are solo times, pair and team times are
 * their own (never mixed with solo), relax solves are counted apart.
 */
readonly final class PlayerPuzzleTimes
{
    public function __construct(
        public string $puzzleId,
        public int $solveCountAny,
        public int $solveCountSolo,
        public null|int $fastestSeconds,
        public null|int $latestSeconds,
        public null|DateTimeImmutable $lastSolvedAt,
        public null|int $fastestPairSeconds,
        public null|int $fastestTeamSeconds,
        public int $relaxSolveCount,
    ) {
    }

    /**
     * The latest result is a different one from the personal best - the only
     * case the latest time is worth a second glance.
     */
    public function latestDiffersFromFastest(): bool
    {
        return $this->latestSeconds !== null && $this->latestSeconds !== $this->fastestSeconds;
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     solve_count_any: int|string,
     *     solve_count_solo: int|string,
     *     fastest_seconds: null|int|string,
     *     latest_seconds: null|int|string,
     *     fastest_pair_seconds: null|int|string,
     *     fastest_team_seconds: null|int|string,
     *     relax_solve_count: int|string,
     *     last_solved_at: null|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            solveCountAny: (int) $row['solve_count_any'],
            solveCountSolo: (int) $row['solve_count_solo'],
            fastestSeconds: $row['fastest_seconds'] !== null ? (int) $row['fastest_seconds'] : null,
            latestSeconds: $row['latest_seconds'] !== null ? (int) $row['latest_seconds'] : null,
            lastSolvedAt: $row['last_solved_at'] !== null ? new DateTimeImmutable($row['last_solved_at']) : null,
            fastestPairSeconds: $row['fastest_pair_seconds'] !== null ? (int) $row['fastest_pair_seconds'] : null,
            fastestTeamSeconds: $row['fastest_team_seconds'] !== null ? (int) $row['fastest_team_seconds'] : null,
            relaxSolveCount: (int) $row['relax_solve_count'],
        );
    }
}
