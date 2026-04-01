<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use Symfony\Contracts\Service\ResetInterface;

final class DerivedMetricsCalculator implements ResetInterface
{
    private const int MINIMUM_FOR_MEMORABILITY = 8;
    private const int MINIMUM_FOR_SENSITIVITY = 20;
    private const int MINIMUM_FOR_PREDICTABILITY = 20;
    private const int MINIMUM_FOR_BOX_DEPENDENCE_UNBOXED = 10;
    private const int MINIMUM_FOR_BOX_DEPENDENCE_BOXED = 5;
    private const int MINIMUM_FOR_IMPROVEMENT_CEILING = 20;

    /** @var array<string, list<array{player_id: string, seconds_to_solve: int|string, first_attempt: bool, unboxed: bool, baseline_seconds: int|string|null, solve_date: string}>>|null */
    private null|array $solveDataCache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Bulk-load all solve data for puzzles with difficulty scores in one query.
     */
    public function preloadAllData(): void
    {
        /** @var list<array{puzzle_id: string, player_id: string, seconds_to_solve: int|string, first_attempt: bool, unboxed: bool, baseline_seconds: int|string|null, solve_date: string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                pst.puzzle_id,
                pst.player_id,
                pst.seconds_to_solve,
                pst.first_attempt,
                pst.unboxed,
                COALESCE(pst.finished_at, pst.tracked_at) AS solve_date,
                pb.baseline_seconds
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            LEFT JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND pst.puzzle_id IN (SELECT puzzle_id FROM puzzle_difficulty WHERE difficulty_score IS NOT NULL)
            ORDER BY pst.puzzle_id, pst.player_id, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
        ");

        $cache = [];

        foreach ($rows as $row) {
            $cache[$row['puzzle_id']][] = $row;
        }

        $this->solveDataCache = $cache;
    }

    public function clearPreloadedData(): void
    {
        $this->solveDataCache = null;
    }

    public function reset(): void
    {
        $this->solveDataCache = null;
    }

    /**
     * Calculate all derived metrics except memorability normalization.
     * Memorability returns the raw puzzle learning rate — the orchestrator
     * normalizes it against the global median in a second pass.
     *
     * @return array{
     *     memorability_score: float|null,
     *     skill_sensitivity_score: float|null,
     *     predictability_score: float|null,
     *     box_dependence_score: float|null,
     *     improvement_ceiling_score: float|null,
     * }
     */
    public function calculateForPuzzle(string $puzzleId): array
    {
        if ($this->solveDataCache !== null) {
            $boxedIndices = $this->computeIndicesFromCache($puzzleId, boxedOnly: true);
            $unboxedIndices = $this->computeIndicesFromCache($puzzleId, unboxedOnly: true);
        } else {
            $boxedIndices = $this->fetchDifficultyIndices($puzzleId, boxedOnly: true);
            $unboxedIndices = $this->fetchDifficultyIndices($puzzleId, unboxedOnly: true);
        }

        return [
            'memorability_score' => $this->computePuzzleLearningRate($puzzleId),
            'skill_sensitivity_score' => $this->computeSkillSensitivity($boxedIndices),
            'predictability_score' => $this->computePredictability($boxedIndices),
            'box_dependence_score' => $this->computeBoxDependence($unboxedIndices, $boxedIndices),
            'improvement_ceiling_score' => $this->computeImprovementCeiling($puzzleId),
        ];
    }

    /**
     * Compute difficulty indices from preloaded cache data.
     *
     * @return list<float>
     */
    private function computeIndicesFromCache(string $puzzleId, bool $unboxedOnly = false, bool $boxedOnly = false): array
    {
        $solves = $this->solveDataCache[$puzzleId] ?? [];
        $indices = [];

        foreach ($solves as $row) {
            if ($unboxedOnly && !$row['unboxed']) {
                continue;
            }

            if ($boxedOnly && $row['unboxed']) {
                continue;
            }

            $baseline = (int) ($row['baseline_seconds'] ?? 0);

            if ($baseline <= 0) {
                continue;
            }

            $index = (int) $row['seconds_to_solve'] / $baseline;

            if ($index > 5.0) {
                continue;
            }

            $indices[] = $index;
        }

        return $indices;
    }

    /**
     * v2 Memorability: Learning curve approach.
     * For each player with 3+ attempts, compute (attempt1 - attempt3) / attempt1.
     * Returns the raw puzzle learning rate (median of per-player rates).
     * The orchestrator normalizes against the global median.
     */
    private function computePuzzleLearningRate(string $puzzleId): null|float
    {
        if ($this->solveDataCache !== null) {
            return $this->computeLearningRateFromCache($puzzleId);
        }

        /** @var list<array{player_id: string, seconds_to_solve: int|string, attempt_num: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT
                pst.player_id,
                pst.seconds_to_solve,
                ROW_NUMBER() OVER (PARTITION BY pst.player_id ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC) AS attempt_num
            FROM puzzle_solving_time pst
            WHERE pst.puzzle_id = :puzzleId
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
        ", ['puzzleId' => $puzzleId]);

        return $this->extractLearningRate($rows);
    }

    /**
     * Compute learning rate from preloaded cache (data is already ordered by player+date).
     */
    private function computeLearningRateFromCache(string $puzzleId): null|float
    {
        $solves = $this->solveDataCache[$puzzleId] ?? [];

        // Data is ordered by player_id, solve_date ASC — compute attempt numbers
        $playerAttempts = [];
        $playerCounter = [];

        foreach ($solves as $row) {
            $playerId = $row['player_id'];
            $playerCounter[$playerId] = ($playerCounter[$playerId] ?? 0) + 1;
            $num = $playerCounter[$playerId];

            if ($num === 1 || $num === 3) {
                $playerAttempts[$playerId][$num] = (int) $row['seconds_to_solve'];
            }
        }

        $learningRates = [];

        foreach ($playerAttempts as $attempts) {
            if (!isset($attempts[1], $attempts[3]) || $attempts[1] <= 0) {
                continue;
            }

            $learningRates[] = ($attempts[1] - $attempts[3]) / $attempts[1];
        }

        if (count($learningRates) < self::MINIMUM_FOR_MEMORABILITY) {
            return null;
        }

        return round($this->computeMedian($learningRates), 6);
    }

    /**
     * @param list<array{player_id: string, seconds_to_solve: int|string, attempt_num: int|string}> $rows
     */
    private function extractLearningRate(array $rows): null|float
    {
        $playerAttempts = [];

        foreach ($rows as $row) {
            $num = (int) $row['attempt_num'];

            if ($num === 1 || $num === 3) {
                $playerAttempts[$row['player_id']][$num] = (int) $row['seconds_to_solve'];
            }
        }

        $learningRates = [];

        foreach ($playerAttempts as $attempts) {
            if (!isset($attempts[1], $attempts[3]) || $attempts[1] <= 0) {
                continue;
            }

            $learningRates[] = ($attempts[1] - $attempts[3]) / $attempts[1];
        }

        if (count($learningRates) < self::MINIMUM_FOR_MEMORABILITY) {
            return null;
        }

        return round($this->computeMedian($learningRates), 6);
    }

    /**
     * @param list<float> $indices
     */
    private function computeSkillSensitivity(array $indices): null|float
    {
        if (count($indices) < self::MINIMUM_FOR_SENSITIVITY) {
            return null;
        }

        sort($indices);
        $count = count($indices);

        $p25Index = intdiv($count, 4);
        $p75Index = intdiv($count * 3, 4);

        $p25 = $indices[$p25Index];
        $p75 = $indices[$p75Index];

        if ($p25 <= 0) {
            return null;
        }

        return round($p75 / $p25, 3);
    }

    /**
     * v2 Predictability: bounded formula 1/(1+CV).
     * Returns 0.0–1.0 scale (1.0 = perfectly predictable).
     *
     * @param list<float> $indices
     */
    private function computePredictability(array $indices): null|float
    {
        if (count($indices) < self::MINIMUM_FOR_PREDICTABILITY) {
            return null;
        }

        $mean = array_sum($indices) / count($indices);

        if ($mean <= 0) {
            return null;
        }

        $sumSquaredDiffs = 0.0;

        foreach ($indices as $index) {
            $sumSquaredDiffs += ($index - $mean) ** 2;
        }

        $stdDev = sqrt($sumSquaredDiffs / count($indices));
        $cv = $stdDev / $mean;

        return round(1.0 / (1.0 + $cv), 3);
    }

    /**
     * @param list<float> $unboxedIndices
     * @param list<float> $boxedIndices
     */
    private function computeBoxDependence(array $unboxedIndices, array $boxedIndices): null|float
    {
        if (count($unboxedIndices) < self::MINIMUM_FOR_BOX_DEPENDENCE_UNBOXED) {
            return null;
        }

        if (count($boxedIndices) < self::MINIMUM_FOR_BOX_DEPENDENCE_BOXED) {
            return null;
        }

        $unboxedMedian = $this->computeMedian($unboxedIndices);
        $boxedMedian = $this->computeMedian($boxedIndices);

        if ($boxedMedian <= 0) {
            return null;
        }

        return round($unboxedMedian / $boxedMedian, 3);
    }

    /**
     * v2 Improvement Ceiling: P50(first_attempt_times) / P10(all_attempt_times).
     * Measures how much a puzzle can be optimized through practice.
     */
    private function computeImprovementCeiling(string $puzzleId): null|float
    {
        if ($this->solveDataCache !== null) {
            $solves = $this->solveDataCache[$puzzleId] ?? [];
            $firstTimes = [];
            $allTimes = [];

            foreach ($solves as $row) {
                $time = (int) $row['seconds_to_solve'];
                $allTimes[] = $time;

                if ($row['first_attempt']) {
                    $firstTimes[] = $time;
                }
            }
        } else {
            /** @var list<array{seconds_to_solve: int|string}> $firstAttemptRows */
            $firstAttemptRows = $this->connection->fetchAllAssociative("
                SELECT pst.seconds_to_solve
                FROM puzzle_solving_time pst
                WHERE pst.puzzle_id = :puzzleId
                    AND pst.first_attempt = true
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
            ", ['puzzleId' => $puzzleId]);

            /** @var list<array{seconds_to_solve: int|string}> $allAttemptRows */
            $allAttemptRows = $this->connection->fetchAllAssociative("
                SELECT pst.seconds_to_solve
                FROM puzzle_solving_time pst
                WHERE pst.puzzle_id = :puzzleId
                    AND pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
            ", ['puzzleId' => $puzzleId]);

            $firstTimes = array_map(static fn (array $r): int => (int) $r['seconds_to_solve'], $firstAttemptRows);
            $allTimes = array_map(static fn (array $r): int => (int) $r['seconds_to_solve'], $allAttemptRows);
        }

        if (count($firstTimes) < self::MINIMUM_FOR_IMPROVEMENT_CEILING) {
            return null;
        }

        $p50First = $this->computePercentile($firstTimes, 50);
        $p10All = $this->computePercentile($allTimes, 10);

        if ($p10All <= 0) {
            return null;
        }

        return round($p50First / $p10All, 3);
    }

    /**
     * @return list<float>
     */
    private function fetchDifficultyIndices(
        string $puzzleId,
        bool $unboxedOnly = false,
        bool $boxedOnly = false,
    ): array {
        $conditions = [
            'pst.puzzle_id = :puzzleId',
            "pst.puzzling_type = 'solo'",
            'pst.suspicious = false',
            'pst.seconds_to_solve IS NOT NULL',
        ];

        if ($unboxedOnly) {
            $conditions[] = 'pst.unboxed = true';
        }

        if ($boxedOnly) {
            $conditions[] = 'pst.unboxed = false';
        }

        $where = implode(' AND ', $conditions);

        $sql = "
            SELECT
                pst.seconds_to_solve,
                pb.baseline_seconds
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
            WHERE {$where}
        ";

        /** @var list<array{seconds_to_solve: int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'puzzleId' => $puzzleId,
        ]);

        $indices = [];

        foreach ($rows as $row) {
            $baseline = (int) $row['baseline_seconds'];

            if ($baseline <= 0) {
                continue;
            }

            $index = (int) $row['seconds_to_solve'] / $baseline;

            if ($index > 5.0) {
                continue;
            }

            $indices[] = $index;
        }

        return $indices;
    }

    /**
     * @param list<float|int> $values
     */
    private function computeMedian(array $values): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($values[$mid - 1] + $values[$mid]) / 2.0;
        }

        return (float) $values[$mid];
    }

    /**
     * Compute a specific percentile from an array of values.
     *
     * @param list<float|int> $values
     */
    private function computePercentile(array $values, int $percentile): float
    {
        sort($values);
        $count = count($values);

        if ($count === 0) {
            return 0.0;
        }

        $index = ($percentile / 100.0) * ($count - 1);
        $lower = (int) floor($index);
        $upper = (int) ceil($index);
        $fraction = $index - $lower;

        if ($lower === $upper) {
            return (float) $values[$lower];
        }

        return $values[$lower] + $fraction * ($values[$upper] - $values[$lower]);
    }
}
