<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;

readonly final class GetPlayerBaselineProgress
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function currentBaseline(string $playerId, int $piecesCount): null|int
    {
        /** @var array{baseline_seconds: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT baseline_seconds FROM player_baseline WHERE player_id = :playerId AND pieces_count = :pc',
            ['playerId' => $playerId, 'pc' => $piecesCount],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['baseline_seconds'];
    }

    /**
     * Get the baseline_seconds at a given percentile for a piece count.
     * This represents the time a player needs to achieve to reach that percentile.
     * Lower baseline = faster = higher percentile.
     */
    public function baselineAtPercentile(int $piecesCount, float $percentile): null|int
    {
        /** @var array{total: int|string}|false $totalRow */
        $totalRow = $this->database->executeQuery(
            'SELECT COUNT(*) AS total FROM player_baseline WHERE pieces_count = :pc',
            ['pc' => $piecesCount],
        )->fetchAssociative();

        if ($totalRow === false || (int) $totalRow['total'] < 3) {
            return null;
        }

        $total = (int) $totalRow['total'];
        $targetOffset = (int) round($total * (1.0 - $percentile / 100.0));
        $targetOffset = max(0, min($total - 1, $targetOffset));

        /** @var array{baseline_seconds: int|string}|false $row */
        $row = $this->database->executeQuery(
            'SELECT baseline_seconds FROM player_baseline WHERE pieces_count = :pc ORDER BY baseline_seconds ASC LIMIT 1 OFFSET :offset',
            ['pc' => $piecesCount, 'offset' => $targetOffset],
        )->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return (int) $row['baseline_seconds'];
    }

    /**
     * Count distinct first-attempt solo puzzles per piece count for a player.
     *
     * @return array<int, array{baseline_solves: int, qualifying_puzzles: int}>
     */
    public function solveProgress(string $playerId, int $minSolversPerPuzzle): array
    {
        /** @var list<array{pieces_count: int|string, solve_count: int|string}> $rows */
        $rows = $this->database->executeQuery("
            WITH first_per_puzzle AS (
                SELECT DISTINCT ON (pst.puzzle_id) p.pieces_count
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                ORDER BY pst.puzzle_id, pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC
            )
            SELECT pieces_count, COUNT(*) AS solve_count
            FROM first_per_puzzle
            GROUP BY pieces_count
            ORDER BY pieces_count
        ", ['playerId' => $playerId])->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $pc = (int) $row['pieces_count'];
            $baselineSolves = (int) $row['solve_count'];

            /** @var array{count: int|string}|false $qualRow */
            $qualRow = $this->database->executeQuery("
                WITH player_first_attempt_puzzles AS (
                    SELECT DISTINCT pst.puzzle_id
                    FROM puzzle_solving_time pst
                    JOIN puzzle p ON p.id = pst.puzzle_id
                    WHERE pst.player_id = :playerId
                        AND p.pieces_count = :piecesCount
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                ),
                puzzle_solver_counts AS (
                    SELECT pst.puzzle_id
                    FROM puzzle_solving_time pst
                    JOIN puzzle p ON p.id = pst.puzzle_id
                    WHERE p.pieces_count = :piecesCount
                        AND pst.first_attempt = true
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                    GROUP BY pst.puzzle_id
                    HAVING COUNT(*) >= :minSolvers
                )
                SELECT COUNT(*) AS count
                FROM player_first_attempt_puzzles pfp
                JOIN puzzle_difficulty pd ON pd.puzzle_id = pfp.puzzle_id
                JOIN puzzle_solver_counts psc ON psc.puzzle_id = pfp.puzzle_id
                WHERE pd.difficulty_score IS NOT NULL AND pd.confidence != 'insufficient'
            ", ['playerId' => $playerId, 'piecesCount' => $pc, 'minSolvers' => $minSolversPerPuzzle])->fetchAssociative();

            $result[$pc] = [
                'baseline_solves' => $baselineSolves,
                'qualifying_puzzles' => $qualRow !== false ? (int) $qualRow['count'] : 0,
            ];
        }

        return $result;
    }
}
