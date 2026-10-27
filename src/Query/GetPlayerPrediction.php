<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\PredictionsSideBySide;
use SpeedPuzzling\Web\Value\SolveConditions;
use SpeedPuzzling\Web\Value\SolveMoment;
use SpeedPuzzling\Web\Value\TimePredictionSource;

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
        private CandidatePredictionCalculator $candidateCalculator = new CandidatePredictionCalculator(),
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

    /**
     * Every predictor's prediction of a solve being added now (SolvingTimePredictor, the live side-by-side rows): the
     * statistical model's (prior-only allowed) and, for a first try, the image / blend ones from the estimate of the
     * puzzle's current picture - read by the statistical statement itself (two LEFT JOINs), no statement of its own.
     * The candidate model's (v4, never shown) too, once it is fitted: its inputs and fit ride on the same statements
     * (StatisticalInputsSql::CANDIDATE_COLUMNS, PredictionModelParametersProvider::CANDIDATE_SQL) - the shown predictions
     * are computed exactly as before.
     */
    public function sideBySide(
        string $playerId,
        string $puzzleId,
        null|string $excludeTimeId = null,
        null|SolveMoment $before = null,
        SolveConditions $conditions = new SolveConditions(),
    ): PredictionsSideBySide {
        $personal = $this->personalPredictionParts($playerId, $puzzleId, $excludeTimeId, $before, withCandidate: true);

        if ($personal !== null) {
            [$personalPrediction, $gapDays, $repeatSpread, $piecesCount, $candidateParameters] = $personal;
            $candidate = $candidateParameters !== null
                ? $this->candidateCalculator->repeatWindow($personalPrediction, $repeatSpread, PiecesGroup::fromPiecesCount($piecesCount), $gapDays, $candidateParameters)
                : null;

            return PredictionsSideBySide::repeat($personalPrediction, TimePredictionSource::Live, $candidate);
        }

        $row = $this->statisticalRow($playerId, $puzzleId, withImageEstimate: true, withCandidate: true);

        if ($row === null) {
            return PredictionsSideBySide::firstTry(TimePredictionSource::Live, null);
        }

        $estimate = ImagePriorInput::fromRow($row);
        $inputs = StatisticalInputsSql::hydrate($row);
        [$statistical, $image, $blend] = $this->calculator->sideBySide(
            $inputs,
            $estimate,
            PredictionModelParametersProvider::fromJson(is_string($row['model_parameters']) ? $row['model_parameters'] : null),
        );
        $candidateParameters = PredictionModelParametersProvider::candidateFromJson(is_string($row['candidate_parameters'] ?? null) ? $row['candidate_parameters'] : null);
        $candidate = $candidateParameters !== null
            ? $this->candidateCalculator->firstTry(StatisticalInputsSql::hydrateCandidate($row, $inputs->withImageEstimate($estimate)), $candidateParameters, $conditions)
            : null;

        return PredictionsSideBySide::firstTry(TimePredictionSource::Live, $statistical, $estimate, $image, $blend)
            ->withCandidate($candidateParameters !== null, $candidate, TimePredictionSource::Live);
    }

    private function personalPrediction(string $playerId, string $puzzleId, null|string $excludeTimeId, null|SolveMoment $before): null|TimePredictionResult
    {
        return $this->personalPredictionParts($playerId, $puzzleId, $excludeTimeId, $before, withCandidate: false)[0] ?? null;
    }

    /**
     * The personal prediction and what the candidate's repeat window needs besides it: the days since the last solve,
     * the player's repeat spread, the piece count and (with $withCandidate) the candidate's fit.
     *
     * @return null|array{0: TimePredictionResult, 1: int, 2: float, 3: int, 4: null|CandidateModelParameters}
     */
    private function personalPredictionParts(string $playerId, string $puzzleId, null|string $excludeTimeId, null|SolveMoment $before, bool $withCandidate): null|array
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
        $gapDays = TimePredictionCalculator::gapDays($before->solvedAt ?? $this->clock->now(), $lastSolvedAt);
        $gapBucket = TimePredictionCalculator::classifyGap($gapDays);

        [$playerRatio, $repeatResiduals, $parameters, $candidateParameters] = $this->playerRatioAndRepeats($playerId, $transition, $excludeTimeId, $before, $withCandidate);
        $globalRatios = $this->getGlobalRatios($piecesCount, $transition, $gapBucket);
        $globalRatioForGap = $globalRatios[$gapBucket] ?? null;
        // The "all" bucket is only needed to gap-correct a player ratio
        $globalRatioAll = $playerRatio !== null ? ($globalRatios['all'] ?? null) : null;

        $repeatSpread = TimePredictionCalculator::playerSpread($repeatResiduals);

        return [
            $this->calculator->personal(
                $times,
                TimePredictionCalculator::resolveRatio($playerRatio, $globalRatioForGap, $globalRatioAll),
                $repeatSpread,
                $piecesCount,
                $parameters,
            ),
            $gapDays,
            $repeatSpread,
            $piecesCount,
            $candidateParameters,
        ];
    }

    /**
     * The player's improvement ratio for the transition, the residuals of their stored repeat predictions before the
     * solve (the window's spread, RepeatResiduals) and the fitted model parameters - one statement.
     *
     * @return array{0: null|float, 1: list<float>, 2: PredictionModelParameters, 3: null|CandidateModelParameters}
     */
    private function playerRatioAndRepeats(string $playerId, int $transition, null|string $excludeTimeId, null|SolveMoment $before, bool $withCandidate = false): array
    {
        $repeats = RepeatResiduals::sql($excludeTimeId !== null, $before !== null);
        $parameters = PredictionModelParametersProvider::SQL;
        // Only on the live side-by-side path - a shown reader's statement stays as it was
        $candidate = $withCandidate ? ', (' . PredictionModelParametersProvider::CANDIDATE_SQL . ') AS candidate_parameters' : '';

        $params = ['playerId' => $playerId, 'transition' => $transition, 'modelVersion' => TimePredictionCalculator::MODEL_VERSION];

        if ($withCandidate) {
            $params['candidateModelVersion'] = CandidatePredictionCalculator::MODEL_VERSION;
        }

        if ($excludeTimeId !== null) {
            $params['excludeTimeId'] = $excludeTimeId;
        }

        if ($before !== null) {
            $params['solvedAt'] = $before->solvedAt->format('Y-m-d H:i:s');
            $params['trackedAt'] = $before->trackedAt->format('Y-m-d H:i:s');
        }

        /** @var array{median_ratio: null|float|string, repeats: null|string, parameters: null|string, candidate_parameters?: null|string} $row */
        $row = $this->database->fetchAssociative("
            SELECT
                (SELECT median_ratio FROM player_improvement_ratio WHERE player_id = :playerId AND from_attempt = :transition) AS median_ratio,
                ({$repeats}) AS repeats,
                ({$parameters}) AS parameters
                {$candidate}
        ", $params);

        return [
            $row['median_ratio'] !== null ? (float) $row['median_ratio'] : null,
            RepeatResiduals::fromJson($row['repeats']),
            PredictionModelParametersProvider::fromJson($row['parameters']),
            PredictionModelParametersProvider::candidateFromJson(is_string($row['candidate_parameters'] ?? null) ? $row['candidate_parameters'] : null),
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
        $row = $this->statisticalRow($playerId, $puzzleId, withImageEstimate: false);

        if ($row === null) {
            return null;
        }

        return $this->calculator->statistical(
            StatisticalInputsSql::hydrate($row),
            PredictionModelParametersProvider::fromJson(is_string($row['model_parameters']) ? $row['model_parameters'] : null),
            $allowPriorOnly,
        );
    }

    /**
     * @return null|array<string, mixed>
     */
    private function statisticalRow(string $playerId, string $puzzleId, bool $withImageEstimate, bool $withCandidate = false): null|array
    {
        $columns = StatisticalInputsSql::COLUMNS . ($withImageEstimate ? ', ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS : '');
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::lateralPaceJoins() . ($withImageEstimate ? StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) : '');
        $parameters = PredictionModelParametersProvider::SQL;
        $params = [
            'playerId' => $playerId,
            'puzzleId' => $puzzleId,
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ];

        // The candidate (v4) only on the live side-by-side path - a shown reader's statement stays as it was
        if ($withCandidate) {
            $columns .= ', ' . StatisticalInputsSql::CANDIDATE_COLUMNS . ', (' . PredictionModelParametersProvider::CANDIDATE_SQL . ') AS candidate_parameters';
            $joins .= StatisticalInputsSql::CANDIDATE_JOINS;
            $params['candidateModelVersion'] = CandidatePredictionCalculator::MODEL_VERSION;
        }

        /** @var array<string, mixed>|false $row */
        $row = $this->database->executeQuery("
            SELECT {$columns}, ({$parameters}) AS model_parameters
            FROM puzzle p
            {$joins}
            WHERE p.id = :puzzleId
        ", $params)->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
