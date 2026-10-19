<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Contracts\Service\ResetInterface;

final class PuzzleDifficultyCalculator implements ResetInterface
{
    private const int MINIMUM_INDICES = 5;
    private const float OUTLIER_CEILING = 5.0;

    private null|FirstAttemptSet $preloaded = null;

    public function __construct(
        private readonly PlayerBaselineCalculator $baselineCalculator,
        private readonly PaceTimelineLoader $paceTimelineLoader,
    ) {
    }

    /**
     * Bulk-load every qualifying first attempt with its solver's pace as of the solve: one query for
     * the solves, one for the whole pace history, the lookups in PHP. Returns the set, which the
     * batch's prediction model step works on too.
     */
    public function preloadAllData(null|PaceTimeline $timeline = null): FirstAttemptSet
    {
        return $this->preloaded = FirstAttemptSet::build(
            $this->baselineCalculator->iterateQualifyingFirstAttempts(paceOrder: true),
            $timeline ?? $this->paceTimelineLoader->all(),
        );
    }

    public function clearPreloadedData(): void
    {
        $this->preloaded = null;
    }

    public function reset(): void
    {
        $this->preloaded = null;
    }

    /**
     * @return array{
     *     difficulty_score: float|null,
     *     difficulty_tier: DifficultyTier|null,
     *     confidence: MetricConfidence,
     *     sample_size: int,
     *     indices_p25: float|null,
     *     indices_p75: float|null,
     *     sum_log_indices: float,
     * }
     */
    public function calculateForPuzzle(string $puzzleId): array
    {
        if ($this->preloaded !== null) {
            return $this->calculateFromIndices($this->preloaded->indicesOfPuzzle($puzzleId));
        }

        return $this->calculateFromSolves($this->fetchFirstAttemptsWithBaselines($puzzleId) ?? []);
    }

    /**
     * calculateForPuzzle(), or null while the pace history is not built (PaceHistoryReadiness) - the
     * incremental handler's way: the readiness comes with the solves, in the same statement.
     *
     * @return null|array{
     *     difficulty_score: float|null,
     *     difficulty_tier: DifficultyTier|null,
     *     confidence: MetricConfidence,
     *     sample_size: int,
     *     indices_p25: float|null,
     *     indices_p75: float|null,
     *     sum_log_indices: float,
     * }
     */
    public function calculateForPuzzleOnBuiltPaceHistory(string $puzzleId): null|array
    {
        $solves = $this->fetchFirstAttemptsWithBaselines($puzzleId);

        return $solves !== null ? $this->calculateFromSolves($solves) : null;
    }

    /**
     * Difficulty from the first attempts of a puzzle (one per player), each with its solver's pace as
     * of that solve (PaceTimeline::paceAt()). Shared with PredictionReconstructor, which passes only
     * the first attempts before a past solve.
     *
     * sample_size is k, the number of indices; sum_log_indices is the sum of their natural logs - with
     * k the input of a shrunk difficulty on read, also for k < 5 (0.0 for k = 0).
     *
     * @param list<array{seconds_to_solve: int|string, baseline_seconds: int|string}> $solves
     * @return array{
     *     difficulty_score: float|null,
     *     difficulty_tier: DifficultyTier|null,
     *     confidence: MetricConfidence,
     *     sample_size: int,
     *     indices_p25: float|null,
     *     indices_p75: float|null,
     *     sum_log_indices: float,
     * }
     */
    public function calculateFromSolves(array $solves): array
    {
        return $this->calculateFromIndices($this->computeDifficultyIndices($solves));
    }

    /**
     * calculateFromSolves() from the indices themselves (FirstAttemptSet holds them).
     *
     * @param list<float> $indices
     * @return array{
     *     difficulty_score: float|null,
     *     difficulty_tier: DifficultyTier|null,
     *     confidence: MetricConfidence,
     *     sample_size: int,
     *     indices_p25: float|null,
     *     indices_p75: float|null,
     *     sum_log_indices: float,
     * }
     */
    public function calculateFromIndices(array $indices): array
    {
        // Sorted before summing: the same indices always give the same sum, whatever order they came in
        sort($indices);

        $sumLogIndices = round(array_sum(array_map(log(...), $indices)), 9);

        if (count($indices) < self::MINIMUM_INDICES) {
            return [
                'difficulty_score' => null,
                'difficulty_tier' => null,
                'confidence' => MetricConfidence::fromSampleSize(count($indices), self::MINIMUM_INDICES),
                'sample_size' => count($indices),
                'indices_p25' => null,
                'indices_p75' => null,
                'sum_log_indices' => $sumLogIndices,
            ];
        }

        $score = $this->computeMedianFromSorted($indices);
        $confidence = MetricConfidence::fromSampleSize(count($indices), self::MINIMUM_INDICES);

        return [
            'difficulty_score' => round($score, 4),
            'difficulty_tier' => DifficultyTier::fromScore($score),
            'confidence' => $confidence,
            'sample_size' => count($indices),
            'indices_p25' => round($this->computePercentile($indices, 25), 4),
            'indices_p75' => round($this->computePercentile($indices, 75), 4),
            'sum_log_indices' => $sumLogIndices,
        ];
    }

    /**
     * First attempt of every qualifying player of a puzzle, with the player's pace as of that solve;
     * null while the pace history is not built.
     *
     * @return null|list<array{seconds_to_solve: int|string, baseline_seconds: int|string}>
     */
    private function fetchFirstAttemptsWithBaselines(string $puzzleId): null|array
    {
        $loaded = $this->paceTimelineLoader->forPuzzleFirstAttempts($puzzleId);

        if ($loaded === null) {
            return null;
        }

        [$firstAttempts, $timeline] = $loaded;

        $solves = [];

        foreach ($firstAttempts as $row) {
            $pace = $timeline->paceAt($row['player_id'], (int) $row['pieces_count'], (float) $row['solved_ts'], (float) $row['tracked_ts']);

            if ($pace !== null) {
                $solves[] = ['seconds_to_solve' => $row['seconds_to_solve'], 'baseline_seconds' => $pace];
            }
        }

        return $solves;
    }

    /**
     * Each qualifying player contributes exactly one index (their first attempt).
     *
     * @param list<array{seconds_to_solve: int|string, baseline_seconds: int|string}> $rows
     * @return list<float>
     */
    private function computeDifficultyIndices(array $rows): array
    {
        $indices = [];

        foreach ($rows as $row) {
            $baselineSeconds = (int) $row['baseline_seconds'];

            if ($baselineSeconds <= 0) {
                continue;
            }

            $index = (int) $row['seconds_to_solve'] / $baselineSeconds;

            // Apply outlier ceiling; a 0-second time is no measurement (and has no log for sum_log_indices)
            if ($index <= 0.0 || $index > self::OUTLIER_CEILING) {
                continue;
            }

            $indices[] = $index;
        }

        return $indices;
    }

    /**
     * @param list<float> $sortedValues Already sorted ascending
     */
    private function computeMedianFromSorted(array $sortedValues): float
    {
        $count = count($sortedValues);
        $mid = intdiv($count, 2);

        if ($count % 2 === 0) {
            return ($sortedValues[$mid - 1] + $sortedValues[$mid]) / 2.0;
        }

        return $sortedValues[$mid];
    }

    /**
     * Linear interpolation percentile on already-sorted values.
     *
     * @param list<float> $sortedValues Already sorted ascending
     * @param int $percentile 0-100
     */
    private function computePercentile(array $sortedValues, int $percentile): float
    {
        $count = count($sortedValues);

        if ($count === 1) {
            return $sortedValues[0];
        }

        $rank = ($percentile / 100.0) * ($count - 1);
        $lower = (int) floor($rank);
        $upper = (int) ceil($rank);
        $fraction = $rank - $lower;

        if ($lower === $upper) {
            return $sortedValues[$lower];
        }

        return $sortedValues[$lower] + $fraction * ($sortedValues[$upper] - $sortedValues[$lower]);
    }
}
