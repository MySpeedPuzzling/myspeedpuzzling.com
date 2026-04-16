<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\ActivityCalendarStreakCalculator;
use SpeedPuzzling\Web\Results\PlayerStatsSnapshot;

/**
 * Loads all five metrics required for badge condition evaluation in a fixed set of queries.
 * Team-participation queries use the established (`player_id = :id OR team JSON contains :id`)
 * pattern seen elsewhere in the codebase.
 */
readonly class GetPlayerStatsSnapshot
{
    public function __construct(
        private Connection $database,
        private ActivityCalendarStreakCalculator $streakCalculator,
    ) {
    }

    public function forPlayer(string $playerId): PlayerStatsSnapshot
    {
        return new PlayerStatsSnapshot(
            playerId: $playerId,
            distinctPuzzlesSolved: $this->distinctPuzzlesSolved($playerId),
            totalPiecesSolved: $this->totalPiecesSolved($playerId),
            best500PieceSoloSeconds: $this->best500PieceSoloSeconds($playerId),
            allTimeLongestStreakDays: $this->allTimeLongestStreakDays($playerId),
            teamSolvesCount: $this->teamSolvesCount($playerId),
        );
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
