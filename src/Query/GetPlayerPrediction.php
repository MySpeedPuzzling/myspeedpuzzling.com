<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * Time prediction of one player on one puzzle. Fetches the inputs and hands the
 * math to TimePredictionCalculator (shared with the bulk GetPlayerPredictions).
 */
readonly final class GetPlayerPrediction
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private TimePredictionCalculator $calculator,
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

        /** @var non-empty-list<int> $times */
        $times = array_map(static fn (array $row): int => (int) $row['seconds_to_solve'], $rows);
        $lastSolvedAt = new DateTimeImmutable($rows[$count - 1]['solved_at']);

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

        $transition = TimePredictionCalculator::transitionFor($count);
        $gapBucket = TimePredictionCalculator::classifyGap(
            TimePredictionCalculator::gapDays($this->clock->now(), $lastSolvedAt),
        );

        $playerRatio = $this->getPlayerRatio($playerId, $transition);
        $globalRatioForGap = $this->getGlobalRatio($piecesCount, $transition, $gapBucket);
        // The "all" bucket is only needed to gap-correct a player ratio
        $globalRatioAll = $playerRatio !== null ? $this->getGlobalRatio($piecesCount, $transition, 'all') : null;

        return $this->calculator->personal(
            $times,
            TimePredictionCalculator::resolveRatio($playerRatio, $globalRatioForGap, $globalRatioAll),
        );
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

        return $this->calculator->statistical(
            baselineSeconds: (int) $row['baseline_seconds'],
            difficultyScore: (float) $row['difficulty_score'],
            p25: $row['indices_p25'] !== null ? (float) $row['indices_p25'] : null,
            p75: $row['indices_p75'] !== null ? (float) $row['indices_p75'] : null,
        );
    }
}
