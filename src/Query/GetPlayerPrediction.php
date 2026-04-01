<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Results\TimePredictionResult;

readonly final class GetPlayerPrediction
{
    private const float DEFAULT_IMPROVEMENT_RATIO = 0.90;
    private const int MAX_TRANSITION = 4;

    public function __construct(
        private Connection $database,
    ) {
    }

    public function forPuzzle(string $playerId, string $puzzleId, null|string $excludeTimeId = null): null|TimePredictionResult
    {
        $personalPrediction = $this->personalPrediction($playerId, $puzzleId, $excludeTimeId);

        if ($personalPrediction !== null) {
            return $personalPrediction;
        }

        return $this->statisticalPrediction($playerId, $puzzleId);
    }

    private function personalPrediction(string $playerId, string $puzzleId, null|string $excludeTimeId = null): null|TimePredictionResult
    {
        $excludeFilter = $excludeTimeId !== null ? 'AND pst.id != :excludeTimeId' : '';

        $query = <<<SQL
SELECT pst.seconds_to_solve, COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
FROM puzzle_solving_time pst
WHERE pst.player_id = :playerId
    AND pst.puzzle_id = :puzzleId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.unboxed = false
    {$excludeFilter}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
SQL;

        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        /** @var list<array{seconds_to_solve: int|string, solved_at: string}> $rows */
        $rows = $this->database->executeQuery($query, $params)->fetchAllAssociative();

        $count = count($rows);

        if ($count === 0) {
            return null;
        }

        $times = array_map(static fn (array $row): int => (int) $row['seconds_to_solve'], $rows);
        $bestTime = min($times);
        $lastTime = $times[$count - 1];
        $nextAttemptNumber = $count + 1;

        // Determine gap since last solve
        $lastSolvedAt = new \DateTimeImmutable($rows[$count - 1]['solved_at']);
        $gapDays = (int) (new \DateTimeImmutable())->diff($lastSolvedAt)->days;

        // Get pieces count for global ratio lookup
        /** @var int|string|false $piecesCount */
        $piecesCount = $this->database->fetchOne(
            'SELECT pieces_count FROM puzzle WHERE id = :puzzleId',
            ['puzzleId' => $puzzleId],
        );

        if ($piecesCount === false) {
            return null;
        }

        $piecesCount = (int) $piecesCount;

        // Get improvement ratio
        $transition = min($count, self::MAX_TRANSITION);
        $gapBucket = $this->classifyGap($gapDays);

        $ratio = $this->getPlayerRatio($playerId, $transition);

        if ($ratio !== null) {
            $ratio = $this->applyGapCorrection($ratio, $piecesCount, $transition, $gapBucket);
        } else {
            $ratio = $this->getGlobalRatio($piecesCount, $transition, $gapBucket) ?? self::DEFAULT_IMPROVEMENT_RATIO;
        }

        $ratioPrediction = $lastTime * $ratio;

        if ($count >= 6) {
            // Pure Holt's damped trend
            $predicted = $this->holtsDampedTrend($times);
        } elseif ($count >= 2) {
            // Blend ratio-based + Holt's
            $holtsPrediction = $this->holtsDampedTrend($times);
            $holtsWeight = min(1.0, ($count - 1) / 5);
            $predicted = (int) round($holtsWeight * $holtsPrediction + (1.0 - $holtsWeight) * $ratioPrediction);
        } else {
            // N=1: pure ratio prediction
            $predicted = (int) round($ratioPrediction);
        }

        // Safety floor: can't predict faster than 30% improvement over personal best
        $predicted = max($predicted, (int) round($bestTime * 0.70));

        // Range calculation
        if ($count >= 2) {
            // Use residual-based range from Holt's fitted values
            $fittedValues = $this->holtsFittedValues($times);
            $residuals = [];

            for ($i = 0; $i < $count; $i++) {
                $residuals[] = abs($times[$i] - $fittedValues[$i]);
            }

            $mad = array_sum($residuals) / count($residuals);
            $spread = max($mad * 1.5, $predicted * 0.05);
        } else {
            // N=1: wider spread (15% or 2 minutes minimum)
            $spread = max($predicted * 0.15, 120);
        }

        $rangeLow = (int) round($predicted - $spread);
        $rangeHigh = (int) round($predicted + $spread);

        // Safety: range low can't be below 70% of best time
        $rangeLow = max($rangeLow, (int) round($bestTime * 0.70));
        $rangeLow = max($rangeLow, 1);

        return new TimePredictionResult(
            predictedSeconds: $predicted,
            rangeLowSeconds: $rangeLow,
            rangeHighSeconds: $rangeHigh,
            difficultyForPlayer: 0.0,
            isPersonalized: true,
            personalSolveCount: $count,
            predictedAttemptNumber: $nextAttemptNumber,
            lastTimeSeconds: $lastTime,
        );
    }

    /**
     * Holt's damped trend exponential smoothing — returns predicted next value.
     *
     * @param list<int> $times chronologically ordered solve times
     */
    private function holtsDampedTrend(array $times): int
    {
        $fitted = $this->holtsFittedValues($times);
        $count = count($times);

        // Extract final level and trend from the last fitted step
        $alpha = 0.5;
        $beta = 0.4;
        $phi = 0.8;

        $level = (float) $times[0];
        $trend = (float) ($times[1] - $times[0]);

        for ($i = 1; $i < $count; $i++) {
            $prevLevel = $level;
            $level = $alpha * $times[$i] + (1.0 - $alpha) * ($level + $phi * $trend);
            $trend = $beta * ($level - $prevLevel) + (1.0 - $beta) * $phi * $trend;
        }

        return (int) round($level + $phi * $trend);
    }

    /**
     * Returns fitted values from Holt's damped trend for residual calculation.
     *
     * @param list<int> $times
     * @return list<float>
     */
    private function holtsFittedValues(array $times): array
    {
        $alpha = 0.5;
        $beta = 0.4;
        $phi = 0.8;
        $count = count($times);

        $level = (float) $times[0];
        $trend = (float) ($times[1] - $times[0]);

        $fittedValues = [(float) $times[0]];

        for ($i = 1; $i < $count; $i++) {
            $fittedValues[] = $level + $phi * $trend;
            $prevLevel = $level;
            $level = $alpha * $times[$i] + (1.0 - $alpha) * ($level + $phi * $trend);
            $trend = $beta * ($level - $prevLevel) + (1.0 - $beta) * $phi * $trend;
        }

        return $fittedValues;
    }

    private function getPlayerRatio(string $playerId, int $transition): null|float
    {
        /** @var float|string|false $ratio */
        $ratio = $this->database->fetchOne(
            'SELECT median_ratio FROM player_improvement_ratio WHERE player_id = :playerId AND from_attempt = :transition',
            ['playerId' => $playerId, 'transition' => $transition],
        );

        return $ratio !== false ? (float) $ratio : null;
    }

    private function getGlobalRatio(int $piecesCount, int $transition, string $gapBucket): null|float
    {
        /** @var float|string|false $ratio */
        $ratio = $this->database->fetchOne(
            'SELECT median_ratio FROM global_improvement_ratio WHERE pieces_count = :piecesCount AND from_attempt = :transition AND gap_bucket = :gapBucket',
            ['piecesCount' => $piecesCount, 'transition' => $transition, 'gapBucket' => $gapBucket],
        );

        return $ratio !== false ? (float) $ratio : null;
    }

    /**
     * Apply gap correction to a player-specific ratio using global gap data.
     * gap_correction = global_ratio[transition][gap_bucket] / global_ratio[transition][all]
     */
    private function applyGapCorrection(float $playerRatio, int $piecesCount, int $transition, string $gapBucket): float
    {
        $gapSpecific = $this->getGlobalRatio($piecesCount, $transition, $gapBucket);
        $gapAll = $this->getGlobalRatio($piecesCount, $transition, 'all');

        if ($gapSpecific === null || $gapAll === null || $gapAll == 0.0) {
            return $playerRatio;
        }

        return $playerRatio * ($gapSpecific / $gapAll);
    }

    private function classifyGap(int $gapDays): string
    {
        if ($gapDays < 30) {
            return 'lt30d';
        }

        if ($gapDays < 90) {
            return '1_3m';
        }

        if ($gapDays < 365) {
            return '3_12m';
        }

        return 'gt12m';
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
