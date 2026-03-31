<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

final class ImprovementRatioCalculator implements ResetInterface
{
    private const int MINIMUM_PLAYER_SAMPLES = 3;

    private const string TRANSITIONS_CTE = <<<'SQL'
        WITH numbered_solves AS (
            SELECT
                pst.player_id,
                pst.puzzle_id,
                p.pieces_count,
                pst.seconds_to_solve,
                COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
                ROW_NUMBER() OVER (
                    PARTITION BY pst.player_id, pst.puzzle_id
                    ORDER BY COALESCE(pst.finished_at, pst.tracked_at)
                ) AS attempt_num
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pst.unboxed = false
        ),
        transitions AS (
            SELECT
                n1.player_id,
                n1.pieces_count,
                LEAST(n1.attempt_num, 4)::int AS from_attempt,
                n2.seconds_to_solve::float / n1.seconds_to_solve AS ratio,
                EXTRACT(EPOCH FROM (n2.solved_at - n1.solved_at)) / 86400.0 AS gap_days
            FROM numbered_solves n1
            JOIN numbered_solves n2
                ON n1.player_id = n2.player_id
                AND n1.puzzle_id = n2.puzzle_id
                AND n2.attempt_num = n1.attempt_num + 1
            WHERE n1.seconds_to_solve > 0
                AND n2.seconds_to_solve::float / n1.seconds_to_solve BETWEEN 0.1 AND 5.0
        )
        SQL;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function reset(): void
    {
    }

    /**
     * Compute global improvement ratios for a specific piece count.
     * Returns bucketed ratios + an "all" bucket for gap correction normalization.
     *
     * @return list<array{from_attempt: int, gap_bucket: string, median_ratio: float, sample_size: int}>
     */
    public function computeGlobalRatios(int $piecesCount): array
    {
        $bucketedSql = self::TRANSITIONS_CTE . <<<'SQL'
            SELECT
                from_attempt,
                CASE
                    WHEN gap_days < 30 THEN 'lt30d'
                    WHEN gap_days < 90 THEN '1_3m'
                    WHEN gap_days < 365 THEN '3_12m'
                    ELSE 'gt12m'
                END AS gap_bucket,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ratio) AS median_ratio,
                COUNT(*)::int AS sample_size
            FROM transitions
            WHERE pieces_count = :piecesCount
            GROUP BY from_attempt, gap_bucket
            SQL;

        $allSql = self::TRANSITIONS_CTE . <<<'SQL'
            SELECT
                from_attempt,
                'all' AS gap_bucket,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ratio) AS median_ratio,
                COUNT(*)::int AS sample_size
            FROM transitions
            WHERE pieces_count = :piecesCount
            GROUP BY from_attempt
            SQL;

        /** @var list<array{from_attempt: int|string, gap_bucket: string, median_ratio: float|string, sample_size: int|string}> $bucketedRows */
        $bucketedRows = $this->connection->fetchAllAssociative($bucketedSql, ['piecesCount' => $piecesCount]);

        /** @var list<array{from_attempt: int|string, gap_bucket: string, median_ratio: float|string, sample_size: int|string}> $allRows */
        $allRows = $this->connection->fetchAllAssociative($allSql, ['piecesCount' => $piecesCount]);

        $results = [];

        foreach ([...$bucketedRows, ...$allRows] as $row) {
            $results[] = [
                'from_attempt' => (int) $row['from_attempt'],
                'gap_bucket' => $row['gap_bucket'],
                'median_ratio' => round((float) $row['median_ratio'], 6),
                'sample_size' => (int) $row['sample_size'],
            ];
        }

        return $results;
    }

    /**
     * Compute player-specific improvement ratios (cross-piece-count).
     * Used in batch recalculation.
     *
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    public function computePlayerRatios(string $playerId): array
    {
        return $this->calculateForPlayer($playerId);
    }

    /**
     * Compute player-specific improvement ratios (cross-piece-count).
     * Used for both batch and incremental recalculation.
     *
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    public function calculateForPlayer(string $playerId): array
    {
        $sql = self::TRANSITIONS_CTE . <<<'SQL'
            SELECT
                from_attempt,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ratio) AS median_ratio,
                COUNT(*)::int AS sample_size
            FROM transitions
            WHERE player_id = :playerId
            GROUP BY from_attempt
            HAVING COUNT(*) >= :minSamples
            SQL;

        /** @var list<array{from_attempt: int|string, median_ratio: float|string, sample_size: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'minSamples' => self::MINIMUM_PLAYER_SAMPLES,
        ]);

        $results = [];

        foreach ($rows as $row) {
            $results[] = [
                'from_attempt' => (int) $row['from_attempt'],
                'median_ratio' => round((float) $row['median_ratio'], 6),
                'sample_size' => (int) $row['sample_size'],
            ];
        }

        return $results;
    }
}
