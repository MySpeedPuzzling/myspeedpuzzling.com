<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\MetricConfidence;
use SpeedPuzzling\Web\Value\SkillTier;

readonly final class PlayerSkillCalculator
{
    private const int MINIMUM_QUALIFYING_PUZZLES = 10;

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{
     *     skill_score: float,
     *     skill_tier: SkillTier,
     *     skill_percentile: float,
     *     confidence: MetricConfidence,
     *     qualifying_puzzles_count: int,
     * }|null
     */
    public function calculateForPlayer(string $playerId, int $piecesCount): null|array
    {
        $outperformanceValues = $this->computeOutperformance($playerId, $piecesCount);

        if (count($outperformanceValues) < self::MINIMUM_QUALIFYING_PUZZLES) {
            return null;
        }

        $skillScore = $this->computeMedian($outperformanceValues);
        $percentile = $this->computePercentile($playerId, $piecesCount, $skillScore);
        $confidence = MetricConfidence::fromSampleSize(count($outperformanceValues), self::MINIMUM_QUALIFYING_PUZZLES);

        return [
            'skill_score' => round($skillScore, 4),
            'skill_tier' => SkillTier::fromPercentile($percentile),
            'skill_percentile' => round($percentile, 2),
            'confidence' => $confidence,
            'qualifying_puzzles_count' => count($outperformanceValues),
        ];
    }

    /**
     * For each puzzle the player solved (first attempt, solo), compute:
     * outperformance = puzzle_difficulty / player_difficulty_index
     *
     * @return list<float>
     */
    private function computeOutperformance(string $playerId, int $piecesCount): array
    {
        $sql = "
            WITH player_first_attempts AS (
                SELECT DISTINCT ON (pst.puzzle_id)
                    pst.puzzle_id,
                    pst.seconds_to_solve
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
            SELECT
                pfa.seconds_to_solve,
                pb.baseline_seconds,
                pd.difficulty_score
            FROM player_first_attempts pfa
            JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = :piecesCount
            JOIN puzzle_difficulty pd ON pd.puzzle_id = pfa.puzzle_id
            WHERE pd.difficulty_score IS NOT NULL
                AND pd.confidence != 'insufficient'
        ";

        /** @var list<array{seconds_to_solve: int|string, baseline_seconds: int|string, difficulty_score: float|string}> $rows */
        $rows = $this->connection->fetchAllAssociative($sql, [
            'playerId' => $playerId,
            'piecesCount' => $piecesCount,
        ]);

        $outperformance = [];

        foreach ($rows as $row) {
            $baselineSeconds = (int) $row['baseline_seconds'];
            $difficultyScore = (float) $row['difficulty_score'];
            $solveTime = (int) $row['seconds_to_solve'];

            if ($baselineSeconds <= 0 || $difficultyScore <= 0) {
                continue;
            }

            $playerIndex = $solveTime / $baselineSeconds;

            if ($playerIndex <= 0) {
                continue;
            }

            $outperformance[] = $difficultyScore / $playerIndex;
        }

        return $outperformance;
    }

    /**
     * Compute what percentile this player's skill score falls in
     * among all players with valid skill scores for this piece count.
     */
    private function computePercentile(string $playerId, int $piecesCount, float $skillScore): float
    {
        $result = $this->connection->fetchAssociative("
            SELECT
                COUNT(*) FILTER (WHERE skill_score <= :skillScore) AS below_or_equal,
                COUNT(*) AS total
            FROM player_skill
            WHERE pieces_count = :piecesCount
        ", [
            'skillScore' => $skillScore,
            'piecesCount' => $piecesCount,
        ]);

        /** @var array{below_or_equal: int|string, total: int|string}|false $result */
        if ($result === false || (int) $result['total'] === 0) {
            return 50.0;
        }

        $total = (int) $result['total'];
        $belowOrEqual = (int) $result['below_or_equal'];

        return ($belowOrEqual / $total) * 100.0;
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
