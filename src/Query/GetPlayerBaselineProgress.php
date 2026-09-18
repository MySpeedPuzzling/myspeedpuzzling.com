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
     * Per piece count the player has solved solo: how many distinct puzzles
     * (baseline_solves) and how many of those qualify for skill computation
     * (qualifying_puzzles - a puzzle difficulty exists and at least
     * $minSolversPerPuzzle first-attempt solo times were recorded for it).
     *
     * One query for all piece counts - it used to be one more per piece count
     * (Sentry WEB-BN), each scanning every first attempt of that piece count.
     *
     * @return array<int, array{baseline_solves: int, qualifying_puzzles: int}>
     */
    public function solveProgress(string $playerId, int $minSolversPerPuzzle): array
    {
        /** @var list<array{pieces_count: int|string, solve_count: int|string, qualifying_count: int|string}> $rows */
        $rows = $this->database->executeQuery("
            WITH player_puzzles AS (
                SELECT DISTINCT pst.puzzle_id, p.pieces_count
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
            ),
            qualifying_puzzles AS (
                SELECT fa.puzzle_id
                FROM puzzle_solving_time fa
                JOIN player_puzzles pp ON pp.puzzle_id = fa.puzzle_id
                JOIN puzzle_difficulty pd ON pd.puzzle_id = fa.puzzle_id
                WHERE fa.first_attempt = true
                    AND fa.puzzling_type = 'solo'
                    AND fa.suspicious = false
                    AND fa.seconds_to_solve IS NOT NULL
                    AND pd.difficulty_score IS NOT NULL
                    AND pd.confidence != 'insufficient'
                GROUP BY fa.puzzle_id
                HAVING COUNT(*) >= :minSolvers
            )
            SELECT pp.pieces_count, COUNT(*) AS solve_count, COUNT(qp.puzzle_id) AS qualifying_count
            FROM player_puzzles pp
            LEFT JOIN qualifying_puzzles qp ON qp.puzzle_id = pp.puzzle_id
            GROUP BY pp.pieces_count
            ORDER BY pp.pieces_count
        ", ['playerId' => $playerId, 'minSolvers' => $minSolversPerPuzzle])->fetchAllAssociative();

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['pieces_count']] = [
                'baseline_solves' => (int) $row['solve_count'],
                'qualifying_puzzles' => (int) $row['qualifying_count'],
            ];
        }

        return $result;
    }
}
