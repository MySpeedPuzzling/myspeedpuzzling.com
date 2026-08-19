<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\SolveXpDisplayInfo;
use SpeedPuzzling\Web\Services\Xp\XpCalculator;

readonly class GetSolveXpDisplayInfo
{
    public function __construct(
        private Connection $database,
        private GetPuzzleSpeedPercentiles $getPuzzleSpeedPercentiles,
    ) {
    }

    /**
     * How many solves (any mode, owner or team participant) the player already has of
     * this puzzle — powers the personalized repeat note on the puzzle detail page.
     */
    public function countPlayerSolvesOfPuzzle(string $playerId, string $puzzleId): int
    {
        $sql = <<<SQL
SELECT COUNT(*)
FROM puzzle_solving_time pst
WHERE pst.suspicious = false
  AND pst.puzzle_id = :puzzleId
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        $value = $this->database
            ->executeQuery($sql, ['playerId' => $playerId, 'puzzleId' => $puzzleId])
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Null when the player is not a participant of the solve (or the solve is gone) —
     * the receipt renders nothing in that case.
     */
    public function forPlayerAndSolvingTime(string $playerId, string $solvingTimeId): null|SolveXpDisplayInfo
    {
        $sql = <<<SQL
SELECT
    pst.puzzle_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.tracked_at,
    COALESCE(pst.finished_at, pst.tracked_at) AS earned_at,
    pd.difficulty_tier
FROM puzzle_solving_time pst
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
WHERE pst.id = :solvingTimeId
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
SQL;

        /** @var array{puzzle_id: string, seconds_to_solve: null|int, puzzling_type: string, tracked_at: string, earned_at: string, difficulty_tier: null|int}|false $row */
        $row = $this->database
            ->executeQuery($sql, ['playerId' => $playerId, 'solvingTimeId' => $solvingTimeId])
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $occurrenceSql = <<<SQL
SELECT COUNT(*)
FROM puzzle_solving_time pst
WHERE pst.suspicious = false
  AND pst.puzzle_id = :puzzleId
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
  AND (COALESCE(pst.finished_at, pst.tracked_at), pst.id) < (CAST(:earnedAt AS TIMESTAMP), CAST(:solvingTimeId AS UUID))
SQL;

        $before = $this->database->executeQuery($occurrenceSql, [
            'playerId' => $playerId,
            'puzzleId' => $row['puzzle_id'],
            'earnedAt' => $row['earned_at'],
            'solvingTimeId' => $solvingTimeId,
        ])->fetchOne();

        $isSolo = $row['puzzling_type'] === 'solo';
        $isTimed = $row['seconds_to_solve'] !== null;

        return new SolveXpDisplayInfo(
            occurrenceIndex: (is_numeric($before) ? (int) $before : 0) + 1,
            isTimed: $isTimed,
            isSolo: $isSolo,
            isBackfill: new DateTimeImmutable($row['tracked_at']) < XpCalculator::fullFormulaFrom(),
            puzzleHasDifficultyTier: $row['difficulty_tier'] !== null,
            speedMedianReliable: $isSolo && $isTimed
                && $this->getPuzzleSpeedPercentiles->forPuzzle($row['puzzle_id'])->hasReliableMedian(),
        );
    }
}
