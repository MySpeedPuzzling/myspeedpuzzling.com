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

    public function forPuzzle(string $playerId, string $puzzleId, null|string $excludeTimeId = null): null|TimePredictionResult
    {
        $personalPrediction = $this->tryPersonalPrediction($playerId, $puzzleId, $excludeTimeId);

        if ($personalPrediction !== null) {
            return $personalPrediction;
        }

        return $this->statisticalPrediction($playerId, $puzzleId);
    }

    private function tryPersonalPrediction(string $playerId, string $puzzleId, null|string $excludeTimeId = null): null|TimePredictionResult
    {
        $excludeFilter = $excludeTimeId !== null ? 'AND pst.id != :excludeTimeId' : '';

        $query = <<<SQL
SELECT pst.seconds_to_solve
FROM puzzle_solving_time pst
WHERE pst.player_id = :playerId
    AND pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.unboxed = false
    {$excludeFilter}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC
SQL;

        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        /** @var list<array{seconds_to_solve: int|string}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        if (count($rows) < self::PERSONAL_PREDICTION_MIN_SOLVES) {
            return null;
        }

        $times = array_map(static fn (array $row): int => (int) $row['seconds_to_solve'], $rows);
        $count = count($times);
        $bestTime = min($times);
        $nextAttemptNumber = $count + 1;

        // Holt's damped trend exponential smoothing
        $alpha = 0.5; // level smoothing
        $beta = 0.4;  // trend smoothing
        $phi = 0.8;   // trend dampening (creates diminishing returns)

        $level = (float) $times[0];
        $trend = (float) ($times[1] - $times[0]);

        $fittedValues = [(float) $times[0]];

        for ($i = 1; $i < $count; $i++) {
            $fittedValues[] = $level + $phi * $trend;
            $prevLevel = $level;
            $level = $alpha * $times[$i] + (1.0 - $alpha) * ($level + $phi * $trend);
            $trend = $beta * ($level - $prevLevel) + (1.0 - $beta) * $phi * $trend;
        }

        $predicted = (int) round($level + $phi * $trend);

        // Floor: can't predict more than 10% faster than personal best
        $predicted = max($predicted, (int) round($bestTime * 0.90));

        // Range from residual variance
        $residuals = [];
        for ($i = 0; $i < $count; $i++) {
            $residuals[] = abs($times[$i] - $fittedValues[$i]);
        }

        $mad = array_sum($residuals) / count($residuals);
        $spread = max($mad * 1.5, $predicted * 0.05); // at least 5% spread

        $rangeLow = (int) round($predicted - $spread);
        $rangeHigh = (int) round($predicted + $spread);

        // Safety: range low can't be below 85% of best time
        $rangeLow = max($rangeLow, (int) round($bestTime * 0.85));
        $rangeLow = max($rangeLow, 1);

        return new TimePredictionResult(
            predictedSeconds: $predicted,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $count,
            predictedAttemptNumber: $nextAttemptNumber,
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
