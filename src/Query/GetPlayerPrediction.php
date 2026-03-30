<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TimePredictionResult;

readonly final class GetPlayerPrediction
{
    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPuzzle(string $playerId, string $puzzleId): null|TimePredictionResult
    {
        $query = <<<SQL
SELECT
    pb.baseline_seconds,
    pd.difficulty_score,
    pd.sample_size
FROM player_baseline pb
JOIN puzzle p ON p.id = :puzzleId AND pb.pieces_count = p.pieces_count
JOIN puzzle_difficulty pd ON pd.puzzle_id = :puzzleId
WHERE pb.player_id = :playerId
    AND pd.difficulty_score IS NOT NULL
    AND pd.confidence != 'insufficient'
SQL;

        /** @var array{baseline_seconds: int|string, difficulty_score: float|string, sample_size: int|string}|false $row */
        $row = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        $baseline = (int) $row['baseline_seconds'];
        $difficulty = (float) $row['difficulty_score'];

        $predictedSeconds = (int) round($baseline * $difficulty);

        // v2: Bounded confidence band
        $stdDev = $this->getDifficultyStdDev($puzzleId);
        $rawLow = $baseline * ($difficulty - $stdDev);
        $rawHigh = $baseline * ($difficulty + $stdDev);

        // Floor: lower bound at least 30% of predicted, minimum 1 second
        $rangeLow = (int) round(max($rawLow, $predictedSeconds * 0.30, 1.0));
        // Ceiling: upper bound at most 300% of predicted
        $rangeHigh = (int) round(min($rawHigh, $predictedSeconds * 3.00));

        // Flag wide range for potential UI hiding
        $isWideRange = $rangeHigh > 0 && $rangeLow > 0 && ($rangeHigh / $rangeLow) > 4.0;

        return new TimePredictionResult(
            predictedSeconds: $predictedSeconds,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: $difficulty,
        );
    }

    private function getDifficultyStdDev(string $puzzleId): float
    {
        $query = <<<SQL
SELECT STDDEV_POP(pst.seconds_to_solve::float / pb.baseline_seconds) AS std_dev
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
JOIN player_baseline pb ON pb.player_id = pst.player_id AND pb.pieces_count = p.pieces_count
WHERE pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
SQL;

        /** @var array{std_dev: null|float|string}|false $row */
        $row = $this->database->executeQuery($query, [
            'puzzleId' => $puzzleId,
        ])->fetchAssociative();

        if ($row === false || $row['std_dev'] === null) {
            return 0.15; // Default fallback
        }

        return (float) $row['std_dev'];
    }
}
