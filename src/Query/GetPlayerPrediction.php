<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\SolveMoment;

/**
 * Time prediction of one player on one puzzle. Fetches the inputs and hands the
 * math to TimePredictionCalculator (shared with the bulk GetPlayerPredictions).
 *
 * With $before it predicts one particular solve: only the solves before it count as
 * earlier attempts (and as the player's stored repeat predictions the window rests on) and the
 * gap is measured to it instead of to now. The insights tables are read as they are - see
 * SolvingTimePredictor for when that is right.
 *
 * The fitted model parameters ride on a statement that runs anyway (PredictionModelParametersProvider::SQL),
 * so the model upgrade cost no query: a personal prediction runs 4 statements, a statistical one 2.
 */
readonly final class GetPlayerPrediction
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private TimePredictionCalculator $calculator,
    ) {
    }

    /**
     * @param bool $allowPriorOnly also predict a first try of a puzzle without any difficulty index, from its prior
     *                             alone - stored side by side, not shown on surfaces yet (Insights plan Phase 6)
     */
    public function forPuzzle(
        string $playerId,
        string $puzzleId,
        null|string $excludeTimeId = null,
        null|SolveMoment $before = null,
        bool $allowPriorOnly = false,
    ): null|TimePredictionResult {
        $personalPrediction = $this->personalPrediction($playerId, $puzzleId, $excludeTimeId, $before);

        if ($personalPrediction !== null) {
            return $personalPrediction;
        }

        return $this->statisticalPrediction($playerId, $puzzleId, $allowPriorOnly);
    }

    private function personalPrediction(string $playerId, string $puzzleId, null|string $excludeTimeId, null|SolveMoment $before): null|TimePredictionResult
    {
        $excludeFilter = $excludeTimeId !== null ? 'AND pst.id != :excludeTimeId' : '';
        $beforeFilter = $before !== null
            ? 'AND (COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at) < (CAST(:solvedAt AS timestamp), CAST(:trackedAt AS timestamp))'
            : '';

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
    {$beforeFilter}
ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
SQL;

        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
        ];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        if ($before !== null) {
            $params['solvedAt'] = $before->solvedAt->format('Y-m-d H:i:s');
            $params['trackedAt'] = $before->trackedAt->format('Y-m-d H:i:s');
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
            TimePredictionCalculator::gapDays($before->solvedAt ?? $this->clock->now(), $lastSolvedAt),
        );

        [$playerRatio, $repeatResiduals, $parameters] = $this->playerRatioAndRepeats($playerId, $transition, $excludeTimeId, $before);
        $globalRatios = $this->getGlobalRatios($piecesCount, $transition, $gapBucket);
        $globalRatioForGap = $globalRatios[$gapBucket] ?? null;
        // The "all" bucket is only needed to gap-correct a player ratio
        $globalRatioAll = $playerRatio !== null ? ($globalRatios['all'] ?? null) : null;

        return $this->calculator->personal(
            $times,
            TimePredictionCalculator::resolveRatio($playerRatio, $globalRatioForGap, $globalRatioAll),
            TimePredictionCalculator::playerSpread($repeatResiduals),
            $piecesCount,
            $parameters,
        );
    }

    /**
     * The player's improvement ratio for the transition, the residuals of their stored repeat predictions before the
     * solve (the window's spread, RepeatResiduals) and the fitted model parameters - one statement.
     *
     * @return array{0: null|float, 1: list<float>, 2: PredictionModelParameters}
     */
    private function playerRatioAndRepeats(string $playerId, int $transition, null|string $excludeTimeId, null|SolveMoment $before): array
    {
        $repeats = RepeatResiduals::sql($excludeTimeId !== null, $before !== null);
        $parameters = PredictionModelParametersProvider::SQL;

        $params = ['playerId' => $playerId, 'transition' => $transition, 'modelVersion' => TimePredictionCalculator::MODEL_VERSION];

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        if ($before !== null) {
            $params['solvedAt'] = $before->solvedAt->format('Y-m-d H:i:s');
            $params['trackedAt'] = $before->trackedAt->format('Y-m-d H:i:s');
        }

        /** @var array{median_ratio: null|float|string, repeats: null|string, parameters: null|string} $row */
        $row = $this->database->fetchAssociative("
            SELECT
                (SELECT median_ratio FROM player_improvement_ratio WHERE player_id = :playerId AND from_attempt = :transition) AS median_ratio,
                ({$repeats}) AS repeats,
                ({$parameters}) AS parameters
        ", $params);

        return [
            $row['median_ratio'] !== null ? (float) $row['median_ratio'] : null,
            RepeatResiduals::fromJson($row['repeats']),
            PredictionModelParametersProvider::fromJson($row['parameters']),
        ];
    }

    /**
     * The ratios of the gap bucket and of the "all" bucket in one query (one row per bucket).
     *
     * @return array<string, float> keyed by gap bucket
     */
    private function getGlobalRatios(int $piecesCount, int $transition, string $gapBucket): array
    {
        /** @var list<array{gap_bucket: string, median_ratio: float|string}> $rows */
        $rows = $this->database->fetchAllAssociative(
            'SELECT gap_bucket, median_ratio FROM global_improvement_ratio WHERE pieces_count = :piecesCount AND from_attempt = :transition AND gap_bucket IN (:gapBucket, :allBuckets)',
            ['piecesCount' => $piecesCount, 'transition' => $transition, 'gapBucket' => $gapBucket, 'allBuckets' => 'all'],
        );

        $ratios = [];

        foreach ($rows as $row) {
            $ratios[$row['gap_bucket']] = (float) $row['median_ratio'];
        }

        return $ratios;
    }

    private function statisticalPrediction(string $playerId, string $puzzleId, bool $allowPriorOnly): null|TimePredictionResult
    {
        $columns = StatisticalInputsSql::COLUMNS;
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::lateralPaceJoins();
        $parameters = PredictionModelParametersProvider::SQL;

        /** @var array<string, mixed>|false $row */
        $row = $this->database->executeQuery("
            SELECT {$columns}, ({$parameters}) AS model_parameters
            FROM puzzle p
            {$joins}
            WHERE p.id = :puzzleId
        ", [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
        ])->fetchAssociative();

        if ($row === false) {
            return null;
        }

        return $this->calculator->statistical(
            StatisticalInputsSql::hydrate($row),
            PredictionModelParametersProvider::fromJson(is_string($row['model_parameters']) ? $row['model_parameters'] : null),
            $allowPriorOnly,
        );
    }
}
