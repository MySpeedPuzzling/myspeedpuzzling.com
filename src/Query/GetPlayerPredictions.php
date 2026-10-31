<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\CandidateCentreSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PredictionModelParametersProvider;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\RepeatResiduals;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PictureLookalikes;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\ShownPredictionModel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Time predictions of one player at list scale (puzzle picker cards and
 * filters, collection pages). Same math as GetPlayerPrediction::forPuzzle() —
 * both delegate to TimePredictionCalculator — but the inputs are loaded in bulk:
 * the player's attempts on every puzzle in one query, the player's ratios with
 * their repeat residuals and the fitted model parameters in one, the global
 * ratios in one, and the statistical inputs (StatisticalInputsSql) for the asked
 * puzzle ids in one more. The per-player part is memoised for the request (the
 * picker query and the card rendering both need it), reset() clears it —
 * FrankenPHP worker mode keeps the service instance alive between requests.
 *
 * The SHOWN prediction (PREDICTION_SHOWN_MODEL, ShownPredictionModel): with v7 the candidate's - the same statements,
 * the ratio statement carrying the candidate's fit and the player's candidate columns (StatisticalInputsSql::
 * candidatePlayerColumns(), once), the puzzles' statement the candidate's puzzle columns; the same calculation as
 * GetPlayerPrediction (GetCandidatePrediction::firstTryOf() / repeatOf()). The statistical model's while the candidate
 * has no fit.
 */
final class GetPlayerPredictions implements ResetInterface
{
    /**
     * Personal predictions per player: puzzle id → prediction, for every puzzle
     * the player has at least one solo, valid, non-unboxed attempt on.
     *
     * @var array<string, array<string, TimePredictionResult>>
     */
    private array $personalByPlayer = [];

    /**
     * The fitted model parameters, from the ratio statement of a personal pass.
     *
     * @var array<string, PredictionModelParameters>
     */
    private array $parametersByPlayer = [];

    /**
     * The candidate's fit (the shown v7) and the player's candidate columns, from the ratio statement of a personal pass.
     *
     * @var array<string, array{0: CandidateModelParameters, 1: array<string, mixed>}>
     */
    private array $candidateByPlayer = [];

    private readonly ShownPredictionModel $shownModel;

    public function __construct(
        private readonly Connection $database,
        private readonly ClockInterface $clock,
        private readonly TimePredictionCalculator $calculator,
        private readonly GetCandidatePrediction $getCandidatePrediction,
        // PREDICTION_SHOWN_MODEL: which model the predictions are (docs/features/feature_flags.md)
        #[Autowire(param: 'predictionShownModel')]
        string $shownModel = 'v3',
    ) {
        $this->shownModel = ShownPredictionModel::fromSetting($shownModel);
    }

    public function reset(): void
    {
        $this->personalByPlayer = [];
        $this->parametersByPlayer = [];
        $this->candidateByPlayer = [];
    }

    /**
     * The model parameters the player's predictions were made with - read by the personal pass; a player without
     * any solo solve has no pace either, so nothing of theirs depends on them (defaults, no query).
     */
    public function modelParameters(string $playerId): PredictionModelParameters
    {
        $this->forAllSolvedPuzzles($playerId);

        return $this->parametersByPlayer[$playerId] ?? PredictionModelParameters::defaults();
    }

    /**
     * The candidate's fit and the player's candidate columns the shown predictions are made with (the shown v7) - null
     * while the statistical model makes them (v3, the candidate not fitted, or a player without any solo solve).
     *
     * @return null|array{0: CandidateModelParameters, 1: array<string, mixed>}
     */
    public function candidateOf(string $playerId): null|array
    {
        $this->forAllSolvedPuzzles($playerId);

        return $this->candidateByPlayer[$playerId] ?? null;
    }

    /**
     * Prediction for each of the given puzzles: personal where the player has
     * solved the puzzle before, statistical where the player's baseline and the
     * puzzle's difficulty exist, absent otherwise.
     *
     * @param list<string> $puzzleIds
     *
     * @return array<string, TimePredictionResult> keyed by puzzle id
     */
    public function forPuzzles(string $playerId, array $puzzleIds): array
    {
        if ($puzzleIds === []) {
            return [];
        }

        $personal = $this->forAllSolvedPuzzles($playerId);
        $predictions = [];
        $missing = [];

        foreach ($puzzleIds as $puzzleId) {
            if (isset($personal[$puzzleId])) {
                $predictions[$puzzleId] = $personal[$puzzleId];
            } else {
                $missing[] = $puzzleId;
            }
        }

        // No solo solve = no pace at any piece count: nothing statistical to predict
        if ($missing !== [] && $personal !== []) {
            $candidate = $this->candidateByPlayer[$playerId] ?? null;
            $firstTries = $candidate !== null
                ? $this->candidateFirstTries($playerId, $missing, $candidate[0], $candidate[1])
                : $this->statisticalPredictions($playerId, $missing);

            foreach ($firstTries as $puzzleId => $prediction) {
                $predictions[$puzzleId] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * Personal predictions for every puzzle the player has solo attempts on.
     *
     * @return array<string, TimePredictionResult> keyed by puzzle id
     */
    public function forAllSolvedPuzzles(string $playerId): array
    {
        if (isset($this->personalByPlayer[$playerId])) {
            return $this->personalByPlayer[$playerId];
        }

        return $this->personalByPlayer[$playerId] = $this->personalPredictions($playerId);
    }

    /**
     * @return array<string, TimePredictionResult>
     */
    private function personalPredictions(string $playerId): array
    {
        $query = <<<SQL
SELECT
    pst.puzzle_id,
    p.pieces_count,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
WHERE pst.player_id = :playerId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve IS NOT NULL
    AND pst.unboxed = false
ORDER BY pst.puzzle_id, COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC
SQL;

        /** @var list<array{puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_at: string}> $rows */
        $rows = $this->database->executeQuery($query, ['playerId' => $playerId])->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        /** @var array<string, array{pieces_count: int, times: non-empty-list<int>, last_solved_at: string}> $attempts */
        $attempts = [];

        foreach ($rows as $row) {
            $puzzleId = $row['puzzle_id'];

            if (isset($attempts[$puzzleId])) {
                $attempts[$puzzleId]['times'][] = (int) $row['seconds_to_solve'];
                $attempts[$puzzleId]['last_solved_at'] = $row['solved_at'];
            } else {
                $attempts[$puzzleId] = [
                    'pieces_count' => (int) $row['pieces_count'],
                    'times' => [(int) $row['seconds_to_solve']],
                    'last_solved_at' => $row['solved_at'],
                ];
            }
        }

        [$playerRatios, $repeatSpread, $parameters, $candidate] = $this->playerRatiosAndRepeats($playerId);
        $this->parametersByPlayer[$playerId] = $parameters;

        if ($candidate !== null) {
            $this->candidateByPlayer[$playerId] = $candidate;
        }

        $globalRatios = $this->globalRatios(array_values(array_unique(array_column($attempts, 'pieces_count'))));
        $now = $this->clock->now();
        $predictions = [];

        foreach ($attempts as $puzzleId => $attempt) {
            $transition = TimePredictionCalculator::transitionFor(count($attempt['times']));
            $gapDays = TimePredictionCalculator::gapDays($now, new DateTimeImmutable($attempt['last_solved_at']));
            $gapBucket = TimePredictionCalculator::classifyGap($gapDays);

            $playerRatio = $playerRatios[$transition] ?? null;
            $globalRatioForGap = $globalRatios[$attempt['pieces_count']][$transition][$gapBucket] ?? null;
            $globalRatioAll = $globalRatios[$attempt['pieces_count']][$transition]['all'] ?? null;

            $prediction = $this->calculator->personal(
                $attempt['times'],
                TimePredictionCalculator::resolveRatio($playerRatio, $globalRatioForGap, $globalRatioAll),
                $repeatSpread,
                $attempt['pieces_count'],
                $parameters,
            );

            // The shown v7: the candidate's repeat around the statistical centre (GetCandidatePrediction::repeatOf())
            $predictions[$puzzleId] = $candidate !== null
                ? $this->getCandidatePrediction->repeatOf($prediction, $repeatSpread, $attempt['pieces_count'], $gapDays, $candidate[0])
                : $prediction;
        }

        return $predictions;
    }

    /**
     * The player's ratios, the spread of their stored repeat predictions and the fitted model parameters - one
     * statement, one row; while the candidate is the shown model also its fit and the player's candidate columns (null
     * while it has no fit).
     *
     * @return array{0: array<int, float>, 1: float, 2: PredictionModelParameters, 3: null|array{0: CandidateModelParameters, 1: array<string, mixed>}} transition → median ratio, spread, parameters, candidate
     */
    private function playerRatiosAndRepeats(string $playerId): array
    {
        $repeats = RepeatResiduals::sql(excludeTime: false, before: false);
        $parameters = PredictionModelParametersProvider::SQL;
        $params = ['playerId' => $playerId, 'modelVersion' => TimePredictionCalculator::MODEL_VERSION];
        $candidateColumns = '';

        if ($this->shownModel->isCandidate()) {
            $candidateColumns = ', (' . PredictionModelParametersProvider::CANDIDATE_SQL . ') AS candidate_parameters, ' . StatisticalInputsSql::candidatePlayerColumns();
            $params['candidateModelVersion'] = CandidatePredictionCalculator::MODEL_VERSION;
        }

        /** @var array<string, mixed> $row */
        $row = $this->database->fetchAssociative("
            SELECT
                (SELECT json_object_agg(from_attempt, median_ratio) FROM player_improvement_ratio WHERE player_id = :playerId) AS ratios,
                ({$repeats}) AS repeats,
                ({$parameters}) AS parameters{$candidateColumns}
        ", $params);

        $ratios = [];

        foreach (is_string($row['ratios'] ?? null) ? (array) json_decode($row['ratios'], true, flags: JSON_THROW_ON_ERROR) : [] as $fromAttempt => $ratio) {
            if (is_numeric($ratio)) {
                $ratios[(int) $fromAttempt] = (float) $ratio;
            }
        }

        $candidateParameters = $candidateColumns !== '' ? GetCandidatePrediction::parametersOf($row) : null;

        return [
            $ratios,
            TimePredictionCalculator::playerSpread(RepeatResiduals::fromJson(is_string($row['repeats'] ?? null) ? $row['repeats'] : null)),
            PredictionModelParametersProvider::fromJson(is_string($row['parameters'] ?? null) ? $row['parameters'] : null),
            $candidateParameters !== null ? [$candidateParameters, $row] : null,
        ];
    }

    /**
     * @param list<int> $piecesCounts
     *
     * @return array<int, array<int, array<string, float>>> pieces count → transition → gap bucket → median ratio
     */
    private function globalRatios(array $piecesCounts): array
    {
        if ($piecesCounts === []) {
            return [];
        }

        /** @var list<array{pieces_count: int|string, from_attempt: int|string, gap_bucket: string, median_ratio: float|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT pieces_count, from_attempt, gap_bucket, median_ratio FROM global_improvement_ratio WHERE pieces_count IN (:piecesCounts)',
            ['piecesCounts' => $piecesCounts],
            ['piecesCounts' => ArrayParameterType::INTEGER],
        )->fetchAllAssociative();

        $ratios = [];

        foreach ($rows as $row) {
            $ratios[(int) $row['pieces_count']][(int) $row['from_attempt']][$row['gap_bucket']] = (float) $row['median_ratio'];
        }

        return $ratios;
    }

    /**
     * @param non-empty-list<string> $puzzleIds
     *
     * @return array<string, TimePredictionResult>
     */
    private function statisticalPredictions(string $playerId, array $puzzleIds): array
    {
        $cte = StatisticalInputsSql::ownPaceCte();
        $columns = StatisticalInputsSql::COLUMNS;
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::ownPaceCteJoins();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->executeQuery(
            "WITH {$cte} SELECT {$columns} FROM puzzle p {$joins} WHERE p.id IN (:puzzleIds)",
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds, 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $parameters = $this->modelParameters($playerId);
        $predictions = [];

        foreach ($rows as $row) {
            $prediction = $this->calculator->statistical(StatisticalInputsSql::hydrate($row), $parameters);

            if ($prediction !== null && is_string($row['puzzle_id'])) {
                $predictions[$row['puzzle_id']] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * The candidate's first tries (the shown v7) of the puzzles - one statement of the puzzles' candidate columns, the
     * player's from the ratio statement; a puzzle without any candidate index (k = 0) gets none, like the statistical
     * model's prior-only predictions on every surface.
     *
     * @param non-empty-list<string> $puzzleIds
     * @param array<string, mixed> $player the ratio statement's row (candidatePlayerColumns())
     *
     * @return array<string, TimePredictionResult>
     */
    private function candidateFirstTries(string $playerId, array $puzzleIds, CandidateModelParameters $parameters, array $player): array
    {
        $predictions = [];

        foreach ($this->candidateRows($playerId, $puzzleIds) as $row) {
            $prediction = $this->getCandidatePrediction->firstTryOf(StatisticalInputsSql::withPlayerColumns($row, $player), $parameters);

            if ($prediction !== null && $prediction->kIndices !== 0 && is_string($row['puzzle_id'])) {
                $predictions[$row['puzzle_id']] = $prediction;
            }
        }

        return $predictions;
    }

    /**
     * The player's candidate pace at every piece count of the catalogue (CandidatePredictionCalculator::pace(), the shown
     * v7) - for the Puzzle Picker's SQL centre (CandidateCentreSql). One statement: the catalogue's piece counts (a skip
     * scan of the pieces_count index, StatisticalInputsSql::centreSql()'s), the player's baseline and latest pace row at
     * each, and their 1-4 first tries at each piece count without a pace row (the rule of
     * StatisticalInputsSql::candidateFewColumn(), all piece counts at once). Empty while the candidate is not shown.
     *
     * @return array<int, array{0: float, 1: PaceSource}>
     */
    public function candidatePaces(string $playerId): array
    {
        $candidate = $this->candidateOf($playerId);

        if ($candidate === null) {
            return [];
        }

        $ownPace = StatisticalInputsSql::ownPaceCte();
        $few = StatisticalInputsSql::candidateFewBySizeCte();

        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->executeQuery("
            WITH RECURSIVE piece_sizes AS (
                SELECT min(pieces_count) AS pieces_count FROM puzzle
                UNION ALL
                SELECT (SELECT min(next.pieces_count) FROM puzzle next WHERE next.pieces_count > piece_sizes.pieces_count)
                FROM piece_sizes
                WHERE piece_sizes.pieces_count IS NOT NULL
            ),
            {$ownPace},
            {$few}
            SELECT
                p.pieces_count,
                pb.baseline_seconds,
                pb.baseline_type,
                pb.qualifying_solves_count,
                pb500.baseline_seconds AS pace500_seconds,
                own.drift AS own_drift,
                own.spread AS own_spread,
                five.drift AS drift500,
                five.spread AS spread500,
                few_by_size.few AS candidate_few
            FROM piece_sizes p
            LEFT JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
            LEFT JOIN player_baseline pb500 ON pb500.player_id = :playerId AND pb500.pieces_count = 500 AND pb500.baseline_type = 'direct'
            " . StatisticalInputsSql::ownPaceCteJoins() . '
            LEFT JOIN few_by_size ON few_by_size.pieces_count = p.pieces_count
            WHERE p.pieces_count IS NOT NULL
        ', ['playerId' => $playerId, 'now' => $this->clock->now()->format('Y-m-d H:i:s')])->fetchAllAssociative();

        return CandidateCentreSql::paces($rows, $candidate[1], $candidate[0]);
    }

    /**
     * The puzzles' rows of the candidate's bulk statement: the statistical inputs, the image estimate, the candidate's
     * puzzle columns and the player's own first tries at each size without a pace row (StatisticalInputsSql::
     * candidateFewBySizeCte(): one pass over the player's solves, joined by piece count - the rule of the one-puzzle
     * statement's candidateFewColumn()) - without the player's columns, which ride on the ratio statement once. Measured on
     * the production copy (200 puzzles, a player with ~800 first tries): 23 ms (v3's statement 4 ms; a subquery per puzzle
     * and the plain estimate join: 73 ms).
     *
     * @param non-empty-list<string> $puzzleIds
     *
     * @return list<array<string, mixed>>
     */
    public function candidateRows(string $playerId, array $puzzleIds): array
    {
        $cte = StatisticalInputsSql::ownPaceCte() . ',' . StatisticalInputsSql::candidateFewBySizeCte();
        $columns = StatisticalInputsSql::COLUMNS . ', ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . ', ' . StatisticalInputsSql::CANDIDATE_PUZZLE_COLUMNS . ', ' . StatisticalInputsSql::CANDIDATE_PICTURE_COLUMNS . ', few_by_size.few AS candidate_few';
        $joins = StatisticalInputsSql::JOINS . StatisticalInputsSql::ownPaceCteJoins() . StatisticalInputsSql::imageEstimateLateralJoins() . PictureLookalikes::JOINS . StatisticalInputsSql::CANDIDATE_JOINS
            . ' LEFT JOIN few_by_size ON few_by_size.pieces_count = p.pieces_count';

        /** @var list<array<string, mixed>> */
        return $this->database->executeQuery(
            "WITH {$cte} SELECT {$columns} FROM puzzle p {$joins} WHERE p.id IN (:puzzleIds)",
            ['playerId' => $playerId, 'puzzleIds' => $puzzleIds, 'now' => $this->clock->now()->format('Y-m-d H:i:s')],
            ['puzzleIds' => ArrayParameterType::STRING],
        )->fetchAllAssociative();
    }
}
