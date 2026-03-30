<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\MetricConfidence;
use Symfony\Contracts\Service\ResetInterface;

final class PuzzleDifficultyCalculator implements ResetInterface
{
    private const int MINIMUM_INDICES = 5;
    private const float OUTLIER_CEILING = 5.0;

    /** @var array<string, list<array{seconds_to_solve: int|string, baseline_seconds: int|string}>>|null */
    private null|array $indicesCache = null;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * Bulk-load all first-attempt solves with baselines in one query.
     */
    public function preloadAllData(): void
    {
        /** @var list<array{puzzle_id: string, seconds_to_solve: int|string, baseline_seconds: int|string}> $rows */
        $rows = $this->connection->fetchAllAssociative("
            SELECT puzzle_id, seconds_to_solve, baseline_seconds
            FROM (
                SELECT
                    pst.puzzle_id,
                    pst.player_id,
                    pst.seconds_to_solve,
                    pb.baseline_seconds,
                    ROW_NUMBER() OVER (
                        PARTITION BY pst.puzzle_id, pst.player_id
                        ORDER BY pst.first_attempt DESC, COALESCE(pst.finished_at, pst.tracked_at) ASC
                    ) AS rn
                FROM puzzle_solving_time pst
                JOIN puzzle p ON p.id = pst.puzzle_id
                JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
                WHERE pst.puzzling_type = 'solo'
                    AND pst.suspicious = false
                    AND pst.seconds_to_solve IS NOT NULL
                    AND pst.unboxed = false
            ) sub
            WHERE rn = 1
        ");

        $cache = [];

        foreach ($rows as $row) {
            $cache[$row['puzzle_id']][] = [
                'seconds_to_solve' => $row['seconds_to_solve'],
                'baseline_seconds' => $row['baseline_seconds'],
            ];
        }

        $this->indicesCache = $cache;
    }

    public function clearPreloadedData(): void
    {
        $this->indicesCache = null;
    }

    public function reset(): void
    {
        $this->indicesCache = null;
    }

    /**
     * @return array{
     *     difficulty_score: float|null,
     *     difficulty_tier: DifficultyTier|null,
     *     confidence: MetricConfidence,
     *     sample_size: int,
     *     indices_p25: float|null,
     *     indices_p75: float|null,
     * }
     */
    public function calculateForPuzzle(string $puzzleId): array
    {
        $indices = $this->computeDifficultyIndices($puzzleId);

        if (count($indices) < self::MINIMUM_INDICES) {
            return [
                'difficulty_score' => null,
                'difficulty_tier' => null,
                'confidence' => MetricConfidence::fromSampleSize(count($indices), self::MINIMUM_INDICES),
                'sample_size' => count($indices),
                'indices_p25' => null,
                'indices_p75' => null,
            ];
        }

        sort($indices);

        $score = $this->computeMedianFromSorted($indices);
        $confidence = MetricConfidence::fromSampleSize(count($indices), self::MINIMUM_INDICES);

        return [
            'difficulty_score' => round($score, 4),
            'difficulty_tier' => DifficultyTier::fromScore($score),
            'confidence' => $confidence,
            'sample_size' => count($indices),
            'indices_p25' => round($this->computePercentile($indices, 25), 4),
            'indices_p75' => round($this->computePercentile($indices, 75), 4),
        ];
    }

    /**
     * Compute difficulty indices for all qualifying solves of a puzzle.
     * Each qualifying player contributes exactly one index (their first attempt).
     *
     * @return list<float>
     */
    private function computeDifficultyIndices(string $puzzleId): array
    {
        if ($this->indicesCache !== null) {
            $rows = $this->indicesCache[$puzzleId] ?? [];
        } else {
            $sql = "
                WITH first_attempts AS (
                    SELECT DISTINCT ON (pst.player_id)
                        pst.player_id,
                        pst.seconds_to_solve
                    FROM puzzle_solving_time pst
                    WHERE pst.puzzle_id = :puzzleId
                        AND pst.puzzling_type = 'solo'
                        AND pst.suspicious = false
                        AND pst.seconds_to_solve IS NOT NULL
                        AND pst.unboxed = false
                    ORDER BY pst.player_id,
                        pst.first_attempt DESC,
                        COALESCE(pst.finished_at, pst.tracked_at) ASC
                )
                SELECT
                    fa.seconds_to_solve,
                    pb.baseline_seconds
                FROM first_attempts fa
                JOIN player_baseline pb ON pb.player_id = fa.player_id
                JOIN puzzle p ON p.id = :puzzleId AND pb.pieces_count = p.pieces_count
            ";

            /** @var list<array{seconds_to_solve: int|string, baseline_seconds: int|string}> $rows */
            $rows = $this->connection->fetchAllAssociative($sql, [
                'puzzleId' => $puzzleId,
            ]);
        }

        $indices = [];

        foreach ($rows as $row) {
            $baselineSeconds = (int) $row['baseline_seconds'];

            if ($baselineSeconds <= 0) {
                continue;
            }

            $index = (int) $row['seconds_to_solve'] / $baselineSeconds;

            // Apply outlier ceiling
            if ($index > self::OUTLIER_CEILING) {
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
