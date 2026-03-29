<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;

readonly final class DerivedMetricsCalculator
{
    private const int MINIMUM_FOR_MEMORABILITY = 5;
    private const int MINIMUM_FOR_SENSITIVITY = 10;
    private const int MINIMUM_FOR_PREDICTABILITY = 10;
    private const int MINIMUM_FOR_BOX_DEPENDENCE = 5;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     memorability_score: float|null,
     *     skill_sensitivity_score: float|null,
     *     predictability_score: float|null,
     *     box_dependence_score: float|null,
     * }
     */
    public function calculateForPuzzle(string $puzzleId): array
    {
        $allIndices = $this->fetchDifficultyIndices($puzzleId);
        $firstTryIndices = $this->fetchDifficultyIndices($puzzleId, firstAttemptOnly: true);
        $repeatIndices = $this->fetchDifficultyIndices($puzzleId, repeatOnly: true);
        $unboxedIndices = $this->fetchDifficultyIndices($puzzleId, unboxedOnly: true);
        $boxedIndices = $this->fetchDifficultyIndices($puzzleId, boxedOnly: true);

        return [
            'memorability_score' => $this->computeMemorability($firstTryIndices, $repeatIndices),
            'skill_sensitivity_score' => $this->computeSkillSensitivity($allIndices),
            'predictability_score' => $this->computePredictability($allIndices),
            'box_dependence_score' => $this->computeBoxDependence($unboxedIndices, $boxedIndices),
        ];
    }

    /**
     * @param list<float> $firstTryIndices
     * @param list<float> $repeatIndices
     */
    private function computeMemorability(array $firstTryIndices, array $repeatIndices): null|float
    {
        if (count($repeatIndices) < self::MINIMUM_FOR_MEMORABILITY) {
            return null;
        }

        if (count($firstTryIndices) < self::MINIMUM_FOR_MEMORABILITY) {
            return null;
        }

        $firstTryMedian = $this->computeMedian($firstTryIndices);
        $repeatMedian = $this->computeMedian($repeatIndices);

        if ($repeatMedian <= 0) {
            return null;
        }

        return round($firstTryMedian / $repeatMedian, 3);
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
        $coefficientOfVariation = $stdDev / $mean;

        if ($coefficientOfVariation <= 0) {
            return null;
        }

        return round(1.0 / $coefficientOfVariation, 3);
    }

    /**
     * @param list<float> $unboxedIndices
     * @param list<float> $boxedIndices
     */
    private function computeBoxDependence(array $unboxedIndices, array $boxedIndices): null|float
    {
        if (count($unboxedIndices) < self::MINIMUM_FOR_BOX_DEPENDENCE) {
            return null;
        }

        if (count($boxedIndices) < self::MINIMUM_FOR_BOX_DEPENDENCE) {
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
     * @return list<float>
     */
    private function fetchDifficultyIndices(
        string $puzzleId,
        bool $firstAttemptOnly = false,
        bool $repeatOnly = false,
        bool $unboxedOnly = false,
        bool $boxedOnly = false,
    ): array {
        $conditions = [
            'pst.puzzle_id = :puzzleId',
            "pst.puzzling_type = 'solo'",
            'pst.suspicious = false',
            'pst.seconds_to_solve IS NOT NULL',
        ];

        if ($firstAttemptOnly) {
            $conditions[] = 'pst.first_attempt = true';
        }

        if ($repeatOnly) {
            $conditions[] = 'pst.first_attempt = false';
        }

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
     * @param list<float> $values
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

        return $values[$mid];
    }
}
