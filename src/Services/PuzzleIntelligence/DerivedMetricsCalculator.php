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

    /** @var array<string, list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}>>|null */
    private null|array $solveDataCache = null;

    public function __construct(
        private readonly Connection $connection,
        private readonly PaceTimelineLoader $paceTimelineLoader,
        private readonly PaceHistoryReadiness $paceHistoryReadiness,
    ) {
    }

    /**
     * Bulk-load all solve data for puzzles with difficulty scores in one query. Every solve is divided by its
     * solver's pace as of that solve (PaceTimeline::paceAt(), the difficulty indices' lookup) - the batch hands in
     * the timeline it loaded for the difficulty; without one (the pace history is not built yet) by today's baseline.
     */
    public function preloadAllData(null|PaceTimeline $timeline = null): void
    {
        $condition = 'pst.puzzle_id IN (SELECT puzzle_id FROM puzzle_difficulty WHERE difficulty_score IS NOT NULL)';
        $cache = [];

        foreach ($this->loadSolves($condition, [], $timeline) as $puzzleId => $solves) {
            $cache[$puzzleId] = $solves;
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
     * Solo, non-suspicious, timed solves per puzzle in the order of each player's attempts, each with its solver's
     * pace as of the solve ($timeline) or today's baseline (no timeline).
     *
     * @param array<string, string> $params
     * @return array<string, list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}>>
     */
    private function loadSolves(string $condition, array $params, null|PaceTimeline $timeline): array
    {
        $baselineJoin = $timeline === null
            ? 'LEFT JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count'
            : '';
        $baselineColumn = $timeline === null ? 'pb.baseline_seconds' : 'NULL AS baseline_seconds';

        $rows = $this->connection->executeQuery("
            SELECT
                pst.puzzle_id,
                pst.player_id,
                p.pieces_count,
                pst.seconds_to_solve,
                pst.first_attempt,
                pst.unboxed,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                EXTRACT(EPOCH FROM pst.tracked_at) AS tracked_ts,
                {$baselineColumn}
            FROM puzzle_solving_time pst
            JOIN puzzle p ON p.id = pst.puzzle_id
            {$baselineJoin}
            WHERE pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.seconds_to_solve IS NOT NULL
                AND {$condition}
            ORDER BY pst.puzzle_id, pst.player_id, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
        ", $params)->iterateAssociative();

        $solves = [];

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, player_id: string, pieces_count: int|string, seconds_to_solve: int|string, first_attempt: bool, unboxed: bool, solved_ts: float|int|string, tracked_ts: float|int|string, baseline_seconds: null|int|string} $row */
            $baseline = $timeline !== null
                ? $timeline->paceAt($row['player_id'], (int) $row['pieces_count'], (float) $row['solved_ts'], (float) $row['tracked_ts'])
                : ($row['baseline_seconds'] !== null ? (int) $row['baseline_seconds'] : null);

            $solves[$row['puzzle_id']][] = [
                'player_id' => $row['player_id'],
                'seconds_to_solve' => (int) $row['seconds_to_solve'],
                'first_attempt' => (bool) $row['first_attempt'],
                'unboxed' => (bool) $row['unboxed'],
                'baseline_seconds' => $baseline,
            ];
        }

        return $solves;
    }

    /**
     * One puzzle (the incremental path, RecalculateDerivedMetricsForPuzzle): its solves and only its solvers' pace history.
     *
     * @return list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}>
     */
    private function solvesOfPuzzle(string $puzzleId): array
    {
        $exponent = $this->paceHistoryReadiness->scalingExponent();
        $timeline = null;

        if ($exponent !== null) {
            /** @var list<string> $solvers */
            $solvers = $this->connection->fetchFirstColumn(
                "SELECT DISTINCT player_id FROM puzzle_solving_time WHERE puzzle_id = :puzzleId AND puzzling_type = 'solo' AND suspicious = false AND seconds_to_solve IS NOT NULL",
                ['puzzleId' => $puzzleId],
            );
            $timeline = $this->paceTimelineLoader->forPlayers($solvers, $exponent);
        }

        return $this->loadSolves('pst.puzzle_id = :puzzleId', ['puzzleId' => $puzzleId], $timeline)[$puzzleId] ?? [];
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
        $solves = $this->solveDataCache !== null
            ? ($this->solveDataCache[$puzzleId] ?? [])
            : $this->solvesOfPuzzle($puzzleId);

        $boxedIndices = self::indices($solves, boxedOnly: true);
        $unboxedIndices = self::indices($solves, unboxedOnly: true);

        return [
            'memorability_score' => $this->computeLearningRate($solves),
            'skill_sensitivity_score' => $this->computeSkillSensitivity($boxedIndices),
            'predictability_score' => $this->computePredictability($boxedIndices),
            'box_dependence_score' => $this->computeBoxDependence($unboxedIndices, $boxedIndices),
            'improvement_ceiling_score' => $this->computeImprovementCeiling($solves),
        ];
    }

    /**
     * Each solve ÷ its solver's pace (as of the solve, or today's baseline before the pace history exists); the 5.0
     * outlier ceiling like the difficulty indices.
     *
     * @param list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}> $solves
     * @return list<float>
     */
    private static function indices(array $solves, bool $unboxedOnly = false, bool $boxedOnly = false): array
    {
        $indices = [];

        foreach ($solves as $row) {
            if ($unboxedOnly && !$row['unboxed']) {
                continue;
            }

            if ($boxedOnly && $row['unboxed']) {
                continue;
            }

            $baseline = $row['baseline_seconds'] ?? 0;

            if ($baseline <= 0) {
                continue;
            }

            $index = $row['seconds_to_solve'] / $baseline;

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
     *
     * @param list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}> $solves ordered by player, solve date
     */
    private function computeLearningRate(array $solves): null|float
    {
        $playerAttempts = [];
        $playerCounter = [];

        foreach ($solves as $row) {
            $playerId = $row['player_id'];
            $playerCounter[$playerId] = ($playerCounter[$playerId] ?? 0) + 1;
            $num = $playerCounter[$playerId];

            if ($num === 1 || $num === 3) {
                $playerAttempts[$playerId][$num] = $row['seconds_to_solve'];
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
     *
     * @param list<array{player_id: string, seconds_to_solve: int, first_attempt: bool, unboxed: bool, baseline_seconds: null|int}> $solves
     */
    private function computeImprovementCeiling(array $solves): null|float
    {
        $firstTimes = [];
        $allTimes = [];

        foreach ($solves as $row) {
            $allTimes[] = $row['seconds_to_solve'];

            if ($row['first_attempt']) {
                $firstTimes[] = $row['seconds_to_solve'];
            }
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
