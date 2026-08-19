<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\PlayerPuzzleTimes;

/**
 * One player's history on a list of puzzles, in one query - the "My times"
 * display mode of collection pages (design doc §5). The aggregation mirrors the
 * picker's my_solves / my_team_solves CTEs (GetPuzzlePickerSuggestions), so
 * "solved N×", fastest and latest mean the same thing on both pages: every
 * solving time of mine counts as a solve (incl. team rows where I am only a
 * participant), the times themselves come from solo, non-suspicious, timed
 * solves, "when" = COALESCE(finished_at, tracked_at).
 */
readonly final class GetPlayerPuzzleTimes
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * Puzzles the player has never solved are absent from the result.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, PlayerPuzzleTimes> keyed by puzzle id
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $query = <<<SQL
WITH my_solves AS (
    SELECT
        pst.puzzle_id,
        count(*) AS solve_count_any,
        count(*) FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL) AS solve_count_solo,
        min(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL) AS fastest_seconds,
        (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC)
            FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL))[1] AS latest_seconds,
        max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
    FROM puzzle_solving_time pst
    WHERE pst.player_id = :playerId
        AND pst.puzzle_id IN (:puzzleIds)
    GROUP BY pst.puzzle_id
),
my_team_solves AS (
    SELECT
        pst.puzzle_id,
        count(*) AS solve_count,
        max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
    FROM puzzle_solving_time pst
    WHERE pst.puzzle_id IN (:puzzleIds)
        AND pst.team IS NOT NULL
        AND pst.player_id <> :playerId
        AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    GROUP BY pst.puzzle_id
)
SELECT
    COALESCE(s.puzzle_id, ts.puzzle_id) AS puzzle_id,
    COALESCE(s.solve_count_any, 0) + COALESCE(ts.solve_count, 0) AS solve_count_any,
    COALESCE(s.solve_count_solo, 0) AS solve_count_solo,
    s.fastest_seconds,
    s.latest_seconds,
    GREATEST(s.last_solved_at, ts.last_solved_at) AS last_solved_at
FROM my_solves s
FULL OUTER JOIN my_team_solves ts ON ts.puzzle_id = s.puzzle_id
SQL;

        /**
         * @var list<array{
         *     puzzle_id: string,
         *     solve_count_any: int|string,
         *     solve_count_solo: int|string,
         *     fastest_seconds: null|int|string,
         *     latest_seconds: null|int|string,
         *     last_solved_at: null|string,
         * }> $rows
         */
        $rows = $this->database->executeQuery(
            $query,
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $times = [];

        foreach ($rows as $row) {
            $times[$row['puzzle_id']] = PlayerPuzzleTimes::fromDatabaseRow($row);
        }

        return $times;
    }
}
