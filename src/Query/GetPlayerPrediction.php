<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TimePredictionResult;

readonly final class GetPlayerPrediction
{
    private const int PERSONAL_PREDICTION_MIN_SOLVES = 2;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPuzzle(string $playerId, string $puzzleId): null|TimePredictionResult
    {
        $personalPrediction = $this->tryPersonalPrediction($playerId, $puzzleId);

        if ($personalPrediction !== null) {
            return $personalPrediction;
        }

        return $this->statisticalPrediction($playerId, $puzzleId);
    }

    private function tryPersonalPrediction(string $playerId, string $puzzleId): null|TimePredictionResult
    {
        $query = <<<SQL
SELECT pst.seconds_to_solve
FROM puzzle_solving_time pst
WHERE pst.player_id = :playerId
    AND pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.unboxed = false
ORDER BY pst.seconds_to_solve ASC
SQL;

        /** @var list<array{seconds_to_solve: int|string}> $rows */
        $rows = $this->database->executeQuery($query, [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ])->fetchAllAssociative();

        if (count($rows) < self::PERSONAL_PREDICTION_MIN_SOLVES) {
            return null;
        }

        $times = array_map(static fn (array $row): int => (int) $row['seconds_to_solve'], $rows);
        // Already sorted ASC by query

        $count = count($times);
        $mid = intdiv($count, 2);
        $median = $count % 2 === 0
            ? (int) round(($times[$mid - 1] + $times[$mid]) / 2.0)
            : $times[$mid];

        return new TimePredictionResult(
            predictedSeconds: $median,
            rangeLowSeconds: $times[0],
            rangeHighSeconds: $times[$count - 1],
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $count,
        );
    }

    private function statisticalPrediction(string $playerId, string $puzzleId): null|TimePredictionResult
    {
        $query = <<<SQL
SELECT
    pb.baseline_seconds,
    pd.difficulty_score,
    pd.sample_size,
    pd.indices_p25,
    pd.indices_p75
FROM player_baseline pb
JOIN puzzle p ON p.id = :puzzleId AND pb.pieces_count = p.pieces_count
JOIN puzzle_difficulty pd ON pd.puzzle_id = :puzzleId
WHERE pb.player_id = :playerId
    AND pd.difficulty_score IS NOT NULL
    AND pd.confidence != 'insufficient'
SQL;

        /** @var array{baseline_seconds: int|string, difficulty_score: float|string, sample_size: int|string, indices_p25: float|string|null, indices_p75: float|string|null}|false $row */
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

        // Use pre-computed IQR, with fallback for pre-migration state
        $p25 = $row['indices_p25'] !== null ? (float) $row['indices_p25'] : $difficulty * 0.85;
        $p75 = $row['indices_p75'] !== null ? (float) $row['indices_p75'] : $difficulty * 1.15;

        $rangeLow = (int) round($baseline * $p25);
        $rangeHigh = (int) round($baseline * $p75);

        // Safety bounds
        $rangeLow = (int) max($rangeLow, (int) round($predictedSeconds * 0.30), 1);
        $rangeHigh = (int) min($rangeHigh, (int) round($predictedSeconds * 3.00));

        return new TimePredictionResult(
            predictedSeconds: $predictedSeconds,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: $difficulty,
        );
    }
}
