<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;

/**
 * Loads all metrics required for badge condition evaluation in a fixed set of queries.
 * Every solve metric counts the player's own results and the pair/team results they were in, whoever logged them -
 * the established (`player_id = :id OR team JSON contains :id`) pattern, like the solved puzzles page. The per-solve
 * counters are batched into a single aggregate query so the number of round-trips stays constant as badges are added.
 */
readonly class GetPlayerStatsSnapshot
{
    public function __construct(
        private Connection $database,
        private ActivityCalendarStreakCalculator $streakCalculator,
        private ClockInterface $clock,
    ) {
    }

    public function forPlayer(string $playerId): PlayerStatsSnapshot
    {
        $solveAggregates = $this->solveAggregates($playerId);

        return new PlayerStatsSnapshot(
            playerId: $playerId,
            distinctPuzzlesSolved: $this->distinctPuzzlesSolved($playerId),
            totalPiecesSolved: $this->totalPiecesSolved($playerId),
            best500PieceSoloSeconds: $this->best500PieceSoloSeconds($playerId),
            allTimeLongestStreakDays: $this->allTimeLongestStreakDays($playerId),
            teamSolvesCount: $this->teamSolvesCount($playerId),
            zenPuzzlerSolves: $solveAggregates['zenPuzzlerSolves'],
            firstTrySolves: $solveAggregates['firstTrySolves'],
            unboxedSolves: $solveAggregates['unboxedSolves'],
            brandExplorerManufacturers: $solveAggregates['brandExplorerManufacturers'],
            marathonerSolves: $solveAggregates['marathonerSolves'],
            photographerSolves: $solveAggregates['photographerSolves'],
            steadyHandsQuarters: $this->steadyHandsQuarters($playerId),
            librarianApprovedRequests: $this->librarianApprovedRequests($playerId),
            best1000PieceSoloSeconds: $solveAggregates['best1000PieceSoloSeconds'],
            weekendSolves: $solveAggregates['weekendSolves'],
            catalogerApprovedPuzzles: $this->catalogerApprovedPuzzles($playerId),
            muscleMemorySolves: $solveAggregates['muscleMemorySolves'],
            muscleMemoryUnboxedSolves: $solveAggregates['muscleMemoryUnboxedSolves'],
            nightOwlSolves: $solveAggregates['nightOwlSolves'],
            competitorEvents: $solveAggregates['competitorEvents'],
            onlineCompetitorResults: $solveAggregates['onlineCompetitorResults'],
        );
    }

    /**
     * All per-solve counters in ONE query — every metric here filters on the same base set (the player's results and
     * the pair/team results they were in, never a suspicious one), so they share a single scan with `FILTER`
     * aggregates instead of one query per badge. A pair/team result a teammate logged counts like one the player
     * logged: it is their relaxed solve, their first try, their weekend solve too.
     *
     * The `>= 2000-01-01` guard on the weekend counter is mandatory: the database
     * contains garbage rows dated year 0024 which would otherwise be counted.
     *
     * Muscle Memory is the exception to the participation rule: only the player's own solo results count (relaxed
     * ones too), per 500-piece puzzle - the most-solved puzzle is the value. The CTE keeps it in this one statement.
     * Night Owl looks at the player's own results too, Online Competitor at every result like the rest, and the
     * Competitor events ride along as a CTE (AchievementSqlRules).
     *
     * @return array{
     *     zenPuzzlerSolves: int,
     *     firstTrySolves: int,
     *     unboxedSolves: int,
     *     brandExplorerManufacturers: int,
     *     marathonerSolves: int,
     *     photographerSolves: int,
     *     weekendSolves: int,
     *     best1000PieceSoloSeconds: null|int,
     *     muscleMemorySolves: int,
     *     muscleMemoryUnboxedSolves: int,
     *     nightOwlSolves: int,
     *     onlineCompetitorResults: int,
     *     competitorEvents: int,
     * }
     */
    private function solveAggregates(string $playerId): array
    {
        $competitionJoins = AchievementSqlRules::RESULT_COMPETITION_JOINS;
        $nightOwl = AchievementSqlRules::IS_NIGHT_OWL_RESULT;
        $onlineCompetition = AchievementSqlRules::IS_ONLINE_COMPETITION_RESULT;
        $competitorEvents = AchievementSqlRules::COMPETITOR_EVENTS;

        $sql = <<<SQL
WITH competitor_events AS (
    {$competitorEvents}
),
solo_500_per_puzzle AS (
    SELECT
        COUNT(*) AS solves,
        COUNT(*) FILTER (WHERE solo.unboxed = true) AS unboxed_solves
    FROM puzzle_solving_time solo
    JOIN puzzle solo_puzzle ON solo_puzzle.id = solo.puzzle_id
    WHERE solo.player_id = :playerId
      AND solo.puzzling_type = 'solo'
      AND solo.suspicious = false
      AND solo_puzzle.pieces_count = 500
    GROUP BY solo.puzzle_id
)
SELECT
    COUNT(*) FILTER (WHERE pst.seconds_to_solve IS NULL) AS zen_solves,
    COUNT(*) FILTER (WHERE pst.first_attempt = true) AS first_try_solves,
    COUNT(*) FILTER (WHERE pst.unboxed = true) AS unboxed_solves,
    COUNT(DISTINCT p.manufacturer_id) AS brand_manufacturers,
    COUNT(*) FILTER (WHERE p.pieces_count >= 2000) AS marathoner_solves,
    COUNT(*) FILTER (WHERE pst.finished_puzzle_photo IS NOT NULL) AS photographer_solves,
    COUNT(*) FILTER (
        WHERE EXTRACT(ISODOW FROM COALESCE(pst.finished_at, pst.tracked_at)) IN (6, 7)
          AND COALESCE(pst.finished_at, pst.tracked_at) >= '2000-01-01'
    ) AS weekend_solves,
    MIN(pst.seconds_to_solve) FILTER (
        WHERE pst.puzzling_type = 'solo'
          AND pst.seconds_to_solve IS NOT NULL
          AND p.pieces_count = 1000
    ) AS best_1000_solo_seconds,
    (SELECT COALESCE(MAX(solves), 0) FROM solo_500_per_puzzle) AS muscle_memory_solves,
    (SELECT COALESCE(MAX(unboxed_solves), 0) FROM solo_500_per_puzzle) AS muscle_memory_unboxed_solves,
    COUNT(*) FILTER (WHERE {$nightOwl}) AS night_owl_solves,
    COUNT(*) FILTER (WHERE {$onlineCompetition}) AS online_competition_results,
    (SELECT COUNT(*) FROM competitor_events) AS competitor_events
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN player owning_player ON owning_player.id = CAST(:playerId AS UUID)
{$competitionJoins}
WHERE pst.suspicious = false
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        /** @var array{
         *     zen_solves: int|string,
         *     first_try_solves: int|string,
         *     unboxed_solves: int|string,
         *     brand_manufacturers: int|string,
         *     marathoner_solves: int|string,
         *     photographer_solves: int|string,
         *     weekend_solves: int|string,
         *     best_1000_solo_seconds: null|int|string,
         *     muscle_memory_solves: int|string,
         *     muscle_memory_unboxed_solves: int|string,
         *     night_owl_solves: int|string,
         *     online_competition_results: int|string,
         *     competitor_events: int|string,
         * }|false $row
         */
        $row = $this->database->executeQuery($sql, [
            'playerId' => $playerId,
            'today' => self::today($this->clock),
        ])->fetchAssociative();

        if ($row === false) {
            return [
                'zenPuzzlerSolves' => 0,
                'firstTrySolves' => 0,
                'unboxedSolves' => 0,
                'brandExplorerManufacturers' => 0,
                'marathonerSolves' => 0,
                'photographerSolves' => 0,
                'weekendSolves' => 0,
                'best1000PieceSoloSeconds' => null,
                'muscleMemorySolves' => 0,
                'muscleMemoryUnboxedSolves' => 0,
                'nightOwlSolves' => 0,
                'onlineCompetitorResults' => 0,
                'competitorEvents' => 0,
            ];
        }

        return [
            'zenPuzzlerSolves' => is_numeric($row['zen_solves']) ? (int) $row['zen_solves'] : 0,
            'firstTrySolves' => is_numeric($row['first_try_solves']) ? (int) $row['first_try_solves'] : 0,
            'unboxedSolves' => is_numeric($row['unboxed_solves']) ? (int) $row['unboxed_solves'] : 0,
            'brandExplorerManufacturers' => is_numeric($row['brand_manufacturers']) ? (int) $row['brand_manufacturers'] : 0,
            'marathonerSolves' => is_numeric($row['marathoner_solves']) ? (int) $row['marathoner_solves'] : 0,
            'photographerSolves' => is_numeric($row['photographer_solves']) ? (int) $row['photographer_solves'] : 0,
            'weekendSolves' => is_numeric($row['weekend_solves']) ? (int) $row['weekend_solves'] : 0,
            'best1000PieceSoloSeconds' => is_numeric($row['best_1000_solo_seconds']) ? (int) $row['best_1000_solo_seconds'] : null,
            'muscleMemorySolves' => is_numeric($row['muscle_memory_solves']) ? (int) $row['muscle_memory_solves'] : 0,
            'muscleMemoryUnboxedSolves' => is_numeric($row['muscle_memory_unboxed_solves']) ? (int) $row['muscle_memory_unboxed_solves'] : 0,
            'nightOwlSolves' => is_numeric($row['night_owl_solves']) ? (int) $row['night_owl_solves'] : 0,
            'onlineCompetitorResults' => is_numeric($row['online_competition_results']) ? (int) $row['online_competition_results'] : 0,
            'competitorEvents' => is_numeric($row['competitor_events']) ? (int) $row['competitor_events'] : 0,
        ];
    }

    /**
     * The day Competitor counts up to (an event counts the day after it ended), in UTC like every stored date
     */
    public static function today(ClockInterface $clock): string
    {
        return $clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
    }

    /**
     * Longest run of consecutive calendar quarters that each contain at least one solve
     * (owner or team participation). One SQL returns the distinct (year, quarter) pairs,
     * the island detection runs in PHP — consecutive means `year * 4 + quarter` increments
     * by exactly one. Dates before 2000-01-01 are excluded (the database contains garbage
     * rows dated year 0024).
     */
    private function steadyHandsQuarters(string $playerId): int
    {
        $sql = <<<SQL
SELECT DISTINCT
    CAST(EXTRACT(YEAR FROM COALESCE(finished_at, tracked_at)) AS INTEGER) AS solve_year,
    CAST(EXTRACT(QUARTER FROM COALESCE(finished_at, tracked_at)) AS INTEGER) AS solve_quarter
FROM puzzle_solving_time
WHERE suspicious = false
  AND COALESCE(finished_at, tracked_at) >= '2000-01-01'
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY solve_year, solve_quarter
SQL;

        /** @var list<array{solve_year: int|string, solve_quarter: int|string}> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        $longest = 0;
        $currentRun = 0;
        $previousIndex = null;

        foreach ($rows as $row) {
            $index = (int) $row['solve_year'] * 4 + (int) $row['solve_quarter'];

            $currentRun = $previousIndex !== null && $index === $previousIndex + 1
                ? $currentRun + 1
                : 1;

            $longest = max($longest, $currentRun);
            $previousIndex = $index;
        }

        return $longest;
    }

    private function librarianApprovedRequests(string $playerId): int
    {
        $sql = <<<SQL
SELECT
    (SELECT COUNT(*) FROM puzzle_change_request WHERE reporter_id = :playerId AND status = 'approved')
    +
    (SELECT COUNT(*) FROM puzzle_merge_request WHERE reporter_id = :playerId AND status = 'approved')
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function catalogerApprovedPuzzles(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM puzzle
WHERE approved = true
  AND added_by_user_id = :playerId
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function distinctPuzzlesSolved(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(DISTINCT puzzle_id)
FROM puzzle_solving_time
WHERE suspicious = false
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function totalPiecesSolved(string $playerId): int
    {
        $sql = <<<SQL
SELECT COALESCE(SUM(p.pieces_count), 0)
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.suspicious = false
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function best500PieceSoloSeconds(string $playerId): null|int
    {
        $sql = <<<SQL
SELECT MIN(pst.seconds_to_solve)
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id = :playerId
  AND pst.puzzling_type = 'solo'
  AND pst.suspicious = false
  AND pst.seconds_to_solve IS NOT NULL
  AND p.pieces_count = 500
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : null;
    }

    private function allTimeLongestStreakDays(string $playerId): int
    {
        $sql = <<<SQL
SELECT DISTINCT TO_CHAR(COALESCE(finished_at, tracked_at), 'YYYY-MM-DD') AS solve_day
FROM puzzle_solving_time
WHERE suspicious = false
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY solve_day
SQL;

        /** @var list<array{solve_day: string}> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        $activeDays = array_column($rows, 'solve_day');

        return $this->streakCalculator->calculate($activeDays)->longest;
    }

    private function teamSolvesCount(string $playerId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM puzzle_solving_time
WHERE suspicious = false
  AND puzzling_type IN ('duo', 'team')
  AND (
    player_id = :playerId
    OR (team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        $value = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
