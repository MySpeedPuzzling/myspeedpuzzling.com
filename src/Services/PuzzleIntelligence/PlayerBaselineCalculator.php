<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Symfony\Contracts\Service\ResetInterface;

final class PlayerBaselineCalculator implements ResetInterface
{
    public const int MINIMUM_SOLVE_COUNT = 5;
    private const float DECAY_HALF_LIFE_MONTHS = 18.0;
    private const int SCALING_EXPONENT_MIN_PLAYERS = 50;
    private const float SCALING_EXPONENT_DEFAULT = 1.3;

    /** @var array<string, array<int, list<array{seconds_to_solve: int|string, solve_date: string}>>>|null */
    private null|array $firstAttemptsCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * Bulk-load all first-attempt solves in one query.
     * Call before the per-player loop for O(1) lookups instead of O(players × pieceCounts) queries.
     */
    public function preloadAllFirstAttempts(): void
    {
        /** @var list<array{player_id: string, pieces_count: int|string, seconds_to_solve: int|string, solve_date: string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT player_id, pieces_count, seconds_to_solve, solve_date
            FROM (
                SELECT
                    pst.player_id,
                    p.pieces_count,
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solve_date,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.player_id, pst.puzzle_id
                        ORDER BY pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC
                    ) AS rn
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
            ) sub
            WHERE rn = 1
        ");

        $cache = [];

        foreach ($rows as $row) {
            $cache[$row['player_id']][(int) $row['pieces_count']][] = [
                'seconds_to_solve' => $row['seconds_to_solve'],
                'solve_date' => $row['solve_date'],
            ];
        }

        $this->firstAttemptsCache = $cache;
    }

    public function clearPreloadedData(): void
    {
        $this->firstAttemptsCache = null;
    }

    public function reset(): void
    {
        $this->firstAttemptsCache = null;
    }

    /**
     * Compute a direct baseline for a player at a specific piece count.
     *
     * @return array{baseline_seconds: int, qualifying_count: int}|null
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): null|array
    {
        $solves = $this->firstAttemptsCache !== null
            ? ($this->firstAttemptsCache[$playerId][$piecesCount] ?? [])
            : $this->fetchFirstAttemptSolves($playerId, $piecesCount);

        if (count($solves) < self::MINIMUM_SOLVE_COUNT) {
            return null;
        }

        $now = $this->clock->now();
        $weightedSolves = [];

        foreach ($solves as $solve) {
            $finishedAt = new \DateTimeImmutable($solve['solve_date']);
            $ageInMonths = $this->calculateAgeInMonths($finishedAt, $now);
            $weight = exp(-$ageInMonths / self::DECAY_HALF_LIFE_MONTHS);

            $weightedSolves[] = [
                'seconds' => (int) $solve['seconds_to_solve'],
                'weight' => $weight,
            ];
        }

        $baselineSeconds = $this->computeWeightedMedian($weightedSolves);

        return [
            'baseline_seconds' => $baselineSeconds,
            'qualifying_count' => count($weightedSolves),
        ];
    }

    /**
     * Compute the global scaling exponent from players with direct baselines at 3+ piece counts.
     * Uses log-linear regression: log(time) = log(a) + exponent × log(pieces).
     */
    public function computeScalingExponent(): float
    {
        /** @var list<array{player_id: string, pieces_count: int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT pb.player_id, pb.pieces_count, pb.baseline_seconds
            FROM player_baseline pb
            WHERE pb.baseline_type = 'direct'
            AND pb.player_id IN (
                SELECT player_id FROM player_baseline
                WHERE baseline_type = 'direct'
                GROUP BY player_id
                HAVING COUNT(DISTINCT pieces_count) >= 3
            )
            ORDER BY pb.player_id, pb.pieces_count
        ");

        if ($rows === []) {
            return self::SCALING_EXPONENT_DEFAULT;
        }

        // Group by player
        $playerBaselines = [];

        foreach ($rows as $row) {
            $playerBaselines[$row['player_id']][] = [
                'log_pieces' => log((int) $row['pieces_count']),
                'log_time' => log((int) $row['baseline_seconds']),
            ];
        }

        if (count($playerBaselines) < self::SCALING_EXPONENT_MIN_PLAYERS) {
            return self::SCALING_EXPONENT_DEFAULT;
        }

        // Collect all (log_pieces, log_time) points across all qualifying players
        $sumX = 0.0;
        $sumY = 0.0;
        $sumXX = 0.0;
        $sumXY = 0.0;
        $n = 0;

        foreach ($playerBaselines as $baselines) {
            foreach ($baselines as $point) {
                $sumX += $point['log_pieces'];
                $sumY += $point['log_time'];
                $sumXX += $point['log_pieces'] * $point['log_pieces'];
                $sumXY += $point['log_pieces'] * $point['log_time'];
                $n++;
            }
        }

        // Least squares slope: exponent = (n*sumXY - sumX*sumY) / (n*sumXX - sumX*sumX)
        $denominator = ($n * $sumXX) - ($sumX * $sumX);

        if (abs($denominator) < 1e-10) {
            return self::SCALING_EXPONENT_DEFAULT;
        }

        $exponent = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;

        // Sanity check: exponent should be between 0.5 and 3.0
        if ($exponent < 0.5 || $exponent > 3.0) {
            return self::SCALING_EXPONENT_DEFAULT;
        }

        return round($exponent, 4);
    }

    /**
     * Find all player × piece count combinations that need interpolated/extrapolated baselines.
     * Returns players who solved puzzles at piece counts where they don't have a direct baseline.
     *
     * @return list<array{player_id: string, pieces_count: int}>
     */
    public function findBaselineGaps(): array
    {
        /** @var list<array{player_id: string, pieces_count: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT DISTINCT pst.player_id, p.pieces_count
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND NOT EXISTS (
                    SELECT 1 FROM player_baseline pb
                    WHERE pb.player_id = pst.player_id
                        AND pb.pieces_count = p.pieces_count
                        AND pb.baseline_type = 'direct'
                )
        ");

        return array_map(static fn (array $row): array => [
            'player_id' => $row['player_id'],
            'pieces_count' => (int) $row['pieces_count'],
        ], $rows);
    }

    /**
     * Get all direct baselines for a specific player.
     *
     * @return array<int, int> Map of pieces_count => baseline_seconds
     */
    public function getDirectBaselinesForPlayer(string $playerId): array
    {
        /** @var list<array{pieces_count: int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT pieces_count, baseline_seconds
            FROM player_baseline
            WHERE player_id = :playerId AND baseline_type = 'direct'
            ORDER BY pieces_count
        ", ['playerId' => $playerId]);

        $result = [];

        foreach ($rows as $row) {
            $result[(int) $row['pieces_count']] = (int) $row['baseline_seconds'];
        }

        return $result;
    }

    /**
     * Compute an interpolated baseline using log-space interpolation between two bracketing baselines.
     */
    public function interpolateBaseline(int $targetPieces, int $lowPieces, int $lowBaseline, int $highPieces, int $highBaseline): int
    {
        $logTarget = log($targetPieces);
        $logLow = log($lowPieces);
        $logHigh = log($highPieces);
        $logBaselineLow = log($lowBaseline);
        $logBaselineHigh = log($highBaseline);

        $logBaseline = $logBaselineLow + ($logTarget - $logLow) / ($logHigh - $logLow) * ($logBaselineHigh - $logBaselineLow);

        return (int) round(exp($logBaseline));
    }

    /**
     * Compute an extrapolated baseline using the global scaling exponent.
     */
    public function extrapolateBaseline(int $targetPieces, int $knownPieces, int $knownBaseline, float $scalingExponent): int
    {
        $baseline = $knownBaseline * (($targetPieces / $knownPieces) ** $scalingExponent);

        return (int) round($baseline);
    }

    /**
     * Fetch first-attempt solo solves for a player and piece count.
     * Uses the "eldest as proxy" rule.
     *
     * @return list<array{seconds_to_solve: int|string, solve_date: string}>
     */
    private function fetchFirstAttemptSolves(string $playerId, int $piecesCount): array
    {
        $sql = "
            WITH first_attempts AS (
                SELECT DISTINCT ON (pst.puzzle_id)
                    pst.seconds_to_solve,
                    COALESCE(pst.finished_at, pst.tracked_at) AS solve_date
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                WHERE pst.player_id = :playerId
                    AND p.pieces_count = :piecesCount
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                ORDER BY pst.puzzle_id,
                    pst.first_attempt DESC,
                    COALESCE(pst.finished_at, pst.tracked_at) ASC
            )
            SELECT seconds_to_solve, solve_date
            FROM first_attempts
        ";

        /** @var list<array{seconds_to_solve: int|string, solve_date: string}> */
        return $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ]);
    }

    /**
     * @param list<array{seconds: int, weight: float}> $weightedSolves
     */
    private function computeWeightedMedian(array $weightedSolves): int
    {
        usort($weightedSolves, static fn (array $a, array $b): int => $a['seconds'] <=> $b['seconds']);

        $totalWeight = array_sum(array_column($weightedSolves, 'weight'));
        $halfWeight = $totalWeight / 2.0;

        $cumulativeWeight = 0.0;

        foreach ($weightedSolves as $solve) {
            $cumulativeWeight += $solve['weight'];

            if ($cumulativeWeight >= $halfWeight) {
                return $solve['seconds'];
            }
        }

        $lastKey = array_key_last($weightedSolves);
        assert($lastKey !== null);

        return $weightedSolves[$lastKey]['seconds'];
    }

    private function calculateAgeInMonths(\DateTimeImmutable $from, \DateTimeImmutable $to): float
    {
        $diff = $from->diff($to);

        return ($diff->y * 12) + $diff->m + ($diff->d / 30.0);
    }
}
