<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

final class ImprovementRatioCalculator implements ResetInterface
{
    private const int MINIMUM_PLAYER_SAMPLES = 3;

    /** @var array<string, list<array{from_attempt: int, ratio: float, gap_days: float}>>|null */
    private null|array $playerTransitionsCache = null;

    /** @var array<int, list<array{from_attempt: int, ratio: float, gap_days: float}>>|null */
    private null|array $piecesCountTransitionsCache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Bulk-load all transition data in one query, compute transitions in PHP.
     * Call once before the per-player/global loops for O(1) lookups.
     */
    public function preloadAllTransitions(): void
    {
        /** @var list<array{player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_at: string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT pst.player_id, pst.puzzle_id, p.pieces_count, pst.seconds_to_solve,
                   COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pst.unboxed = false
            ORDER BY pst.player_id, pst.puzzle_id, COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at ASC
        ");

        $playerCache = [];
        $piecesCache = [];

        // Iterate ordered rows, group by (player_id, puzzle_id) to compute transitions
        $prevPlayerId = null;
        $prevPuzzleId = null;
        $attemptNum = 0;
        $prevSeconds = 0;
        $prevSolvedAt = '';
        $prevPiecesCount = 0;

        foreach ($rows as $row) {
            $playerId = $row['player_id'];
            $puzzleId = $row['puzzle_id'];
            $seconds = (int) $row['seconds_to_solve'];
            $solvedAt = $row['solved_at'];
            $piecesCount = (int) $row['pieces_count'];

            if ($playerId !== $prevPlayerId || $puzzleId !== $prevPuzzleId) {
                // New group — reset attempt counter
                $attemptNum = 1;
                $prevPlayerId = $playerId;
                $prevPuzzleId = $puzzleId;
                $prevSeconds = $seconds;
                $prevSolvedAt = $solvedAt;
                $prevPiecesCount = $piecesCount;
                continue;
            }

            // Consecutive attempt — compute transition
            $attemptNum++;

            if ($prevSeconds > 0) {
                $ratio = $seconds / $prevSeconds;

                if ($ratio >= 0.1 && $ratio <= 5.0) {
                    $gapDays = (strtotime($solvedAt) - strtotime($prevSolvedAt)) / 86400.0;
                    $fromAttempt = min($attemptNum - 1, 4);

                    $transition = [
                        'from_attempt' => $fromAttempt,
                        'ratio' => $ratio,
                        'gap_days' => $gapDays,
                    ];

                    $playerCache[$playerId][] = $transition;
                    $piecesCache[$prevPiecesCount][] = $transition;
                }
            }

            $prevSeconds = $seconds;
            $prevSolvedAt = $solvedAt;
            $prevPiecesCount = $piecesCount;
        }

        $this->playerTransitionsCache = $playerCache;
        $this->piecesCountTransitionsCache = $piecesCache;
    }

    public function clearPreloadedData(): void
    {
        $this->playerTransitionsCache = null;
        $this->piecesCountTransitionsCache = null;
    }

    public function reset(): void
    {
        $this->clearPreloadedData();
    }

    /**
     * Compute global improvement ratios for a specific piece count.
     * Returns bucketed ratios + an "all" bucket for gap correction normalization.
     *
     * @return list<array{from_attempt: int, gap_bucket: string, median_ratio: float, sample_size: int}>
     */
    public function computeGlobalRatios(int $piecesCount): array
    {
        if ($this->piecesCountTransitionsCache !== null) {
            return $this->computeGlobalRatiosFromCache($piecesCount);
        }

        return $this->computeGlobalRatiosFromDb($piecesCount);
    }

    /**
     * Compute player-specific improvement ratios (cross-piece-count).
     * Used for both batch and incremental recalculation.
     *
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    public function calculateForPlayer(string $playerId): array
    {
        if ($this->playerTransitionsCache !== null) {
            return $this->calculateForPlayerFromCache($playerId);
        }

        return $this->calculateForPlayerFromDb($playerId);
    }

    /**
     * Alias for calculateForPlayer — used in batch recalculation.
     *
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    public function computePlayerRatios(string $playerId): array
    {
        return $this->calculateForPlayer($playerId);
    }

    /**
     * @return list<array{from_attempt: int, gap_bucket: string, median_ratio: float, sample_size: int}>
     */
    private function computeGlobalRatiosFromCache(int $piecesCount): array
    {
        $transitions = $this->piecesCountTransitionsCache[$piecesCount] ?? [];

        if ($transitions === []) {
            return [];
        }

        // Group by (from_attempt, gap_bucket)
        $bucketedGroups = [];
        $allGroups = [];

        foreach ($transitions as $t) {
            $fromAttempt = $t['from_attempt'];
            $gapBucket = self::classifyGapBucket($t['gap_days']);

            $bucketedGroups[$fromAttempt][$gapBucket][] = $t['ratio'];
            $allGroups[$fromAttempt][] = $t['ratio'];
        }

        $results = [];

        // Bucketed ratios
        foreach ($bucketedGroups as $fromAttempt => $buckets) {
            foreach ($buckets as $gapBucket => $ratios) {
                $results[] = [
                    'from_attempt' => $fromAttempt,
                    'gap_bucket' => $gapBucket,
                    'median_ratio' => round(self::computeMedian($ratios), 6),
                    'sample_size' => count($ratios),
                ];
            }
        }

        // "all" bucket
        foreach ($allGroups as $fromAttempt => $ratios) {
            $results[] = [
                'from_attempt' => $fromAttempt,
                'gap_bucket' => 'all',
                'median_ratio' => round(self::computeMedian($ratios), 6),
                'sample_size' => count($ratios),
            ];
        }

        return $results;
    }

    /**
     * @return list<array{from_attempt: int, gap_bucket: string, median_ratio: float, sample_size: int}>
     */
    private function computeGlobalRatiosFromDb(int $piecesCount): array
    {
        $transitionsCte = self::buildTransitionsCte();

        $bucketedSql = $transitionsCte . <<<'SQL'
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

        $allSql = $transitionsCte . <<<'SQL'
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
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    private function calculateForPlayerFromCache(string $playerId): array
    {
        $transitions = $this->playerTransitionsCache[$playerId] ?? [];

        if ($transitions === []) {
            return [];
        }

        // Group by from_attempt
        $groups = [];

        foreach ($transitions as $t) {
            $groups[$t['from_attempt']][] = $t['ratio'];
        }

        $results = [];

        foreach ($groups as $fromAttempt => $ratios) {
            if (count($ratios) < self::MINIMUM_PLAYER_SAMPLES) {
                continue;
            }

            $results[] = [
                'from_attempt' => $fromAttempt,
                'median_ratio' => round(self::computeMedian($ratios), 6),
                'sample_size' => count($ratios),
            ];
        }

        return $results;
    }

    /**
     * SQL fallback for incremental recalculation (single player).
     * Filters by player_id INSIDE the CTE to avoid materializing the full table.
     *
     * @return list<array{from_attempt: int, median_ratio: float, sample_size: int}>
     */
    private function calculateForPlayerFromDb(string $playerId): array
    {
        $sql = <<<'SQL'
            WITH numbered_solves AS (
                SELECT
                    pst.player_id,
                    pst.puzzle_id,
                    p.pieces_count,
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.player_id, pst.puzzle_id
                        ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at ASC
                    ) AS attempt_num
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND pst.puzzling_type = 'solo'
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
            SELECT
                from_attempt,
                PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY ratio) AS median_ratio,
                COUNT(*)::int AS sample_size
            FROM transitions
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

    private static function buildTransitionsCte(): string
    {
        return <<<'SQL'
            WITH numbered_solves AS (
                SELECT
                    pst.player_id,
                    pst.puzzle_id,
                    p.pieces_count,
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.player_id, pst.puzzle_id
                        ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at ASC
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
    }

    private static function classifyGapBucket(float $gapDays): string
    {
        if ($gapDays < 30) {
            return 'lt30d';
        }

        if ($gapDays < 90) {
            return '1_3m';
        }

        if ($gapDays < 365) {
            return '3_12m';
        }

        return 'gt12m';
    }

    /**
     * @param list<float> $values
     */
    private static function computeMedian(array $values): float
    {
        sort($values);
        $count = count($values);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return $values[$mid];
    }
}
