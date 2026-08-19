<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Community statistics of one puzzle, always split by discipline - solo, duo
 * and team are different disciplines and are never merged; solvedTimes is the
 * total across them.
 */
readonly final class PuzzleStatisticsResult
{
    public function __construct(
        public string $puzzleId,
        public int $solvedTimes,
        public PuzzleDisciplineStatistics $solo,
        public PuzzleDisciplineStatistics $duo,
        public PuzzleDisciplineStatistics $team,
    ) {
    }

    /**
     * A puzzle nobody has solved has no puzzle_statistics row at all.
     */
    public static function empty(string $puzzleId): self
    {
        return new self(
            puzzleId: $puzzleId,
            solvedTimes: 0,
            solo: PuzzleDisciplineStatistics::empty(),
            duo: PuzzleDisciplineStatistics::empty(),
            team: PuzzleDisciplineStatistics::empty(),
        );
    }

    /**
     * @param array{
     *     puzzle_id: string,
     *     solved_times_count: int|string,
     *     solved_times_solo_count: int|string,
     *     fastest_time_solo: null|int|string,
     *     average_time_solo: null|int|string,
     *     slowest_time_solo: null|int|string,
     *     solved_times_duo_count: int|string,
     *     fastest_time_duo: null|int|string,
     *     average_time_duo: null|int|string,
     *     slowest_time_duo: null|int|string,
     *     solved_times_team_count: int|string,
     *     fastest_time_team: null|int|string,
     *     average_time_team: null|int|string,
     *     slowest_time_team: null|int|string,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            puzzleId: $row['puzzle_id'],
            solvedTimes: (int) $row['solved_times_count'],
            solo: new PuzzleDisciplineStatistics(
                count: (int) $row['solved_times_solo_count'],
                fastestSeconds: self::seconds($row['fastest_time_solo']),
                averageSeconds: self::seconds($row['average_time_solo']),
                slowestSeconds: self::seconds($row['slowest_time_solo']),
            ),
            duo: new PuzzleDisciplineStatistics(
                count: (int) $row['solved_times_duo_count'],
                fastestSeconds: self::seconds($row['fastest_time_duo']),
                averageSeconds: self::seconds($row['average_time_duo']),
                slowestSeconds: self::seconds($row['slowest_time_duo']),
            ),
            team: new PuzzleDisciplineStatistics(
                count: (int) $row['solved_times_team_count'],
                fastestSeconds: self::seconds($row['fastest_time_team']),
                averageSeconds: self::seconds($row['average_time_team']),
                slowestSeconds: self::seconds($row['slowest_time_team']),
            ),
        );
    }

    private static function seconds(null|int|string $value): null|int
    {
        return $value === null ? null : (int) $value;
    }
}
