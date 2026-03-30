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
            ];
        }

        $score = $this->computeMedian($indices);
        $confidence = MetricConfidence::fromSampleSize(count($indices), self::MINIMUM_INDICES);

        return [
            'difficulty_score' => round($score, 4),
            'difficulty_tier' => DifficultyTier::fromScore($score),
            'confidence' => $confidence,
            'sample_size' => count($indices),
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
     * @param list<float> $values
     */
    private function computeMedian(array $values): float
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
