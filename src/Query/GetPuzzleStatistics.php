<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PuzzleStatisticsResult;

/**
 * Community statistics of a list of puzzles in one query, from the
 * precomputed puzzle_statistics table (the public API's puzzle cards; one
 * batch call per list, whatever its size).
 */
readonly final class GetPuzzleStatistics
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Puzzles without a statistics row (never solved) are absent from the result.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PuzzleStatisticsResult> keyed by puzzle id
     */
    public function forPuzzleList(array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
SELECT
    ps.puzzle_id,
    ps.solved_times_count,
    ps.solved_times_solo_count,
    ps.fastest_time_solo,
    ps.average_time_solo,
    ps.slowest_time_solo,
    ps.solved_times_duo_count,
    ps.fastest_time_duo,
    ps.average_time_duo,
    ps.slowest_time_duo,
    ps.solved_times_team_count,
    ps.fastest_time_team,
    ps.average_time_team,
    ps.slowest_time_team
FROM puzzle_statistics ps
WHERE ps.puzzle_id IN (:puzzleIds)
SQL;

        /**
         * @var list<array{
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
         * }> $rows
         */
        $rows = $this->database->executeQuery(
            $query,
            ['puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $statistics = [];

        foreach ($rows as $row) {
            $statistics[$row['puzzle_id']] = PuzzleStatisticsResult::fromDatabaseRow($row);
        }

        return $statistics;
    }
}
