<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\RepeatHistory;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * The prediction model's two jobs (README "Step 8: Time Prediction", "The model's batch step and its daily fit"), both
 * genuine bulk operations over every pace-history row - set-based SQL like the rest of the insights batch:
 *
 * - the 15-minute batch (recalculate()): the drift and spread of every player_pace_history row, replayed with
 *   PredictionEngine over all qualifying first attempts with the stored fit - only the rows whose values changed are
 *   written (~1 s);
 * - the daily fit (fit(), `myspeedpuzzling:fit-prediction-model`): BrandPiecesPrior's groups and every puzzle's share
 *   in them, every puzzle's finished priors (the statistical chain's and the blend's, with the image estimate in front),
 *   the rest of the model (PredictionModelFitter, the last FIT_WINDOW_MONTHS; with the image estimates for the scale of
 *   the picture-only window) into prediction_model_fit, then the drift
 *   and spread with the new fit (~3-4 s - kept out of the 15-minute batch).
 */
readonly final class PredictionModelRecalculator
{
    public const int FIT_WINDOW_MONTHS = 12;

    private const int CHUNK = 5000;

    public function __construct(
        private Connection $connection,
        private TimePredictionCalculator $calculator,
        private PredictionModelFitter $fitter,
        private PredictionModelParametersProvider $parametersProvider,
        private PaceTimelineLoader $paceTimelineLoader,
        private PlayerBaselineCalculator $baselineCalculator,
        private PlayerPaceHistoryWriter $paceHistoryWriter,
    ) {
    }

    /**
     * The batch's step: drift and spread with the stored fit (defaults and no priors before the first fit). The timeline
     * comes with the rows' stored drift and spread (PaceTimelineLoader::all(withStoredStates: true)).
     *
     * @return array{drift_rows_written: int}
     */
    public function recalculate(FirstAttemptSet $set, PaceTimeline $timeline): array
    {
        $parameters = $this->parametersProvider->current();
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();
        $engine->computeDrift($this->storedPriors($this->manufacturerByPuzzle()), $parameters->tau);

        return ['drift_rows_written' => $this->writeDrift($engine)];
    }

    /**
     * The daily fit on everything as it is now, then the drift and spread with it.
     *
     * @return array{prior_groups: int, prior_puzzles: int, priors_seconds: float, drift_rows_written: int, parameters: PredictionModelParameters}
     */
    public function fit(DateTimeImmutable $now): array
    {
        $timeline = $this->paceTimelineLoader->all(withStoredStates: true);
        $set = FirstAttemptSet::build($this->baselineCalculator->iterateQualifyingFirstAttempts(paceOrder: true), $timeline);
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();

        $priorsStartedAt = microtime(true);
        $priors = PriorLookup::fit($set, $this->manufacturerByPuzzle());
        $imageEstimates = $this->imageEstimates();
        $priorRows = $this->puzzlePriors($priors, $imageEstimates);
        $this->writePriors($priors, $priorRows, $now);
        $priorsSeconds = microtime(true) - $priorsStartedAt;

        $from = (float) $now->modify('-' . self::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
        // Fitting tau computes the drift with it (PredictionModelFitter step 2)
        $parameters = $this->fitter->fit($engine, $priors, RepeatHistory::load($this->connection, $from), $from, (float) $now->getTimestamp(), $now, $imageEstimates);
        $this->storeParameters($parameters, $now);

        return [
            'prior_groups' => count($priors->groupSums),
            'prior_puzzles' => count($priorRows),
            'priors_seconds' => round($priorsSeconds, 2),
            'drift_rows_written' => $this->writeDrift($engine),
            'parameters' => $parameters,
        ];
    }

    /**
     * @return array<string, null|string>
     */
    private function manufacturerByPuzzle(): array
    {
        /** @var array<string, null|string> */
        return $this->connection->fetchAllKeyValue('SELECT id, CASE WHEN ' . StatisticalInputsSql::NOT_SECRET . ' THEN manufacturer_id END FROM puzzle p');
    }

    /**
     * @param array<string, null|string> $manufacturers
     */
    private function storedPriors(array $manufacturers): PriorLookup
    {
        $groups = [];

        foreach ($this->connection->iterateAssociative('SELECT manufacturer_id, pieces_count, sum_log_indices, index_count FROM difficulty_prior_group') as $row) {
            /** @var array{manufacturer_id: string, pieces_count: int|string, sum_log_indices: float|string, index_count: int|string} $row */
            $groups[$row['manufacturer_id'] . '|' . $row['pieces_count']] = [(float) $row['sum_log_indices'], (int) $row['index_count']];
        }

        $shares = [];

        foreach ($this->connection->iterateAssociative('SELECT puzzle_id, sum_log_indices, index_count FROM difficulty_prior_puzzle') as $row) {
            /** @var array{puzzle_id: string, sum_log_indices: float|string, index_count: int|string} $row */
            $shares[$row['puzzle_id']] = [(float) $row['sum_log_indices'], (int) $row['index_count']];
        }

        return new PriorLookup($manufacturers, $groups, $shares);
    }

    /**
     * Every puzzle with a share in a group or a prior: its share (0 / 0 without one) and its finished priors as the
     * prior chain gives them now (TimePredictionCalculator::prior()):
     * - prior_log - the statistical (shown) predictor's: the context without the image estimate, so brand × pieces;
     * - blend_prior_log - the blend predictor's: the same context with the estimate of the puzzle's current picture,
     *   so ImageEstimatePrior first, else brand × pieces (Insights 5d - for the Picker and bulk readers once the
     *   estimates are shown, Phase 6).
     * Secret puzzles have no brand in the lookup and no estimate: neither.
     *
     * @param array<string, ImagePriorInput> $imageEstimates imageEstimates()
     * @return array<string, array{0: float, 1: int, 2: null|float, 3: null|float}> puzzle => [share sum, share count, prior log, blend prior log]
     */
    private function puzzlePriors(PriorLookup $priors, array $imageEstimates): array
    {
        $rows = [];

        foreach ($this->connection->iterateKeyValue('SELECT id, pieces_count FROM puzzle') as $puzzleId => $piecesCount) {
            /** @var string $puzzleId */
            /** @var int|string $piecesCount */
            $share = $priors->puzzleShares[$puzzleId] ?? null;
            $context = $priors->contextFor($puzzleId, (int) $piecesCount);
            $prior = $this->calculator->prior($context)?->logDifficulty;
            $estimate = $imageEstimates[$puzzleId] ?? null;
            $blendPrior = $estimate !== null ? $this->calculator->prior($context->withImageEstimate($estimate))?->logDifficulty : $prior;

            if ($share !== null || $prior !== null || $blendPrior !== null) {
                $rows[$puzzleId] = [$share[0] ?? 0.0, $share[1] ?? 0, $prior, $blendPrior];
            }
        }

        return $rows;
    }

    /**
     * The estimate of every puzzle's current picture (any, in-sample too: a current-state prior like the live
     * prediction's, StatisticalInputsSql::imageEstimateJoins()) - none for a secret puzzle.
     *
     * @return array<string, ImagePriorInput>
     */
    private function imageEstimates(): array
    {
        $estimates = [];
        $rows = $this->connection->iterateAssociative('
            SELECT p.id, ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . '
            FROM puzzle p
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . '
            WHERE img.puzzle_id IS NOT NULL
        ');

        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $estimate = ImagePriorInput::fromRow($row);

            if ($estimate !== null && is_string($row['id'])) {
                $estimates[$row['id']] = $estimate;
            }
        }

        return $estimates;
    }

    /**
     * Groups, shares and the finished priors change together (one transaction): a prediction never sees new sums with
     * old shares, nor a prior of another fit than its sums.
     *
     * @param array<string, array{0: float, 1: int, 2: null|float, 3: null|float}> $puzzleRows puzzlePriors()
     */
    private function writePriors(PriorLookup $priors, array $puzzleRows, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($priors, $puzzleRows, $now): void {
            $keys = array_keys($priors->groupSums);

            foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                $manufacturers = [];
                $pieces = [];
                $sums = [];
                $counts = [];

                foreach ($chunk as $key) {
                    [$manufacturer, $piecesCount] = explode('|', $key);
                    $manufacturers[] = $manufacturer;
                    $pieces[] = (int) $piecesCount;
                    $sums[] = $priors->groupSums[$key][0];
                    $counts[] = $priors->groupSums[$key][1];
                }

                $connection->executeStatement('
                    INSERT INTO difficulty_prior_group (manufacturer_id, pieces_count, sum_log_indices, index_count, computed_at)
                    SELECT fresh.manufacturer_id, fresh.pieces_count, fresh.sum_log_indices, fresh.index_count, CAST(:now AS timestamp)
                    FROM unnest(CAST(:manufacturers AS uuid[]), CAST(:pieces AS int[]), CAST(:sums AS double precision[]), CAST(:counts AS int[]))
                        AS fresh (manufacturer_id, pieces_count, sum_log_indices, index_count)
                    ON CONFLICT (manufacturer_id, pieces_count) DO UPDATE SET
                        sum_log_indices = EXCLUDED.sum_log_indices,
                        index_count = EXCLUDED.index_count,
                        computed_at = EXCLUDED.computed_at
                    WHERE (difficulty_prior_group.sum_log_indices, difficulty_prior_group.index_count)
                        IS DISTINCT FROM (EXCLUDED.sum_log_indices, EXCLUDED.index_count)
                ', [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'manufacturers' => self::arrayLiteral($manufacturers),
                    'pieces' => self::arrayLiteral($pieces),
                    'sums' => self::arrayLiteral($sums),
                    'counts' => self::arrayLiteral($counts),
                ]);
            }

            $connection->executeStatement('
                DELETE FROM difficulty_prior_group stale
                WHERE NOT EXISTS (
                    SELECT 1 FROM unnest(CAST(:manufacturers AS uuid[]), CAST(:pieces AS int[])) AS produced (manufacturer_id, pieces_count)
                    WHERE produced.manufacturer_id = stale.manufacturer_id AND produced.pieces_count = stale.pieces_count
                )
            ', [
                'manufacturers' => self::arrayLiteral(array_map(static fn (string $key): string => explode('|', $key)[0], $keys)),
                'pieces' => self::arrayLiteral(array_map(static fn (string $key): int => (int) explode('|', $key)[1], $keys)),
            ]);

            $puzzles = array_map('strval', array_keys($puzzleRows));

            foreach (array_chunk($puzzles, self::CHUNK) as $chunk) {
                $connection->executeStatement('
                    INSERT INTO difficulty_prior_puzzle (puzzle_id, sum_log_indices, index_count, prior_log, blend_prior_log, computed_at)
                    SELECT fresh.puzzle_id, fresh.sum_log_indices, fresh.index_count, fresh.prior_log, fresh.blend_prior_log, CAST(:now AS timestamp)
                    FROM unnest(CAST(:puzzles AS uuid[]), CAST(:sums AS double precision[]), CAST(:counts AS int[]), CAST(:priors AS double precision[]), CAST(:blendPriors AS double precision[]))
                        AS fresh (puzzle_id, sum_log_indices, index_count, prior_log, blend_prior_log)
                    ON CONFLICT (puzzle_id) DO UPDATE SET
                        sum_log_indices = EXCLUDED.sum_log_indices,
                        index_count = EXCLUDED.index_count,
                        prior_log = EXCLUDED.prior_log,
                        blend_prior_log = EXCLUDED.blend_prior_log,
                        computed_at = EXCLUDED.computed_at
                    WHERE (difficulty_prior_puzzle.sum_log_indices, difficulty_prior_puzzle.index_count, difficulty_prior_puzzle.prior_log, difficulty_prior_puzzle.blend_prior_log)
                        IS DISTINCT FROM (EXCLUDED.sum_log_indices, EXCLUDED.index_count, EXCLUDED.prior_log, EXCLUDED.blend_prior_log)
                ', [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'puzzles' => self::arrayLiteral($chunk),
                    'sums' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $puzzleRows[$puzzle][0], $chunk)),
                    'counts' => self::arrayLiteral(array_map(static fn (string $puzzle): int => $puzzleRows[$puzzle][1], $chunk)),
                    'priors' => self::arrayLiteral(array_map(static fn (string $puzzle): null|float => $puzzleRows[$puzzle][2], $chunk)),
                    'blendPriors' => self::arrayLiteral(array_map(static fn (string $puzzle): null|float => $puzzleRows[$puzzle][3], $chunk)),
                ]);
            }

            $connection->executeStatement(
                'DELETE FROM difficulty_prior_puzzle WHERE NOT (puzzle_id = ANY (CAST(:puzzles AS uuid[])))',
                ['puzzles' => self::arrayLiteral($puzzles)],
            );

            self::syncDifficultyPriorsOn($connection);
        });
    }

    /**
     * puzzle_difficulty.prior_log / blend_prior_log = difficulty_prior_puzzle's of the same puzzle (null without one) -
     * the copy the Puzzle Picker reads with the difficulty it joins anyway, one lookup per candidate less. Run by the fit
     * with the prior tables (one transaction) and by the batch after its difficulty step, which can insert rows. Writes
     * only the rows that differ.
     *
     * @return int rows written
     */
    public function syncDifficultyPriors(): int
    {
        return self::syncDifficultyPriorsOn($this->connection);
    }

    private static function syncDifficultyPriorsOn(Connection $connection): int
    {
        return (int) $connection->executeStatement('
            UPDATE puzzle_difficulty pd SET prior_log = fresh.prior_log, blend_prior_log = fresh.blend_prior_log
            FROM (
                SELECT d.puzzle_id, dpp.prior_log, dpp.blend_prior_log
                FROM puzzle_difficulty d
                LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = d.puzzle_id
            ) fresh
            WHERE fresh.puzzle_id = pd.puzzle_id
                AND (pd.prior_log, pd.blend_prior_log) IS DISTINCT FROM (fresh.prior_log, fresh.blend_prior_log)
        ');
    }

    private function storeParameters(PredictionModelParameters $parameters, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('
            INSERT INTO prediction_model_fit (model_version, fitted_at, parameters) VALUES (:version, :now, CAST(:parameters AS jsonb))
            ON CONFLICT (model_version) DO UPDATE SET fitted_at = EXCLUDED.fitted_at, parameters = EXCLUDED.parameters
        ', [
            'version' => TimePredictionCalculator::MODEL_VERSION,
            'now' => $now->format('Y-m-d H:i:s'),
            'parameters' => json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
        ]);
    }

    /**
     * Compares the fresh drift and spread with the ones stored on every row (loaded with the timeline - no second read
     * of the table) and writes the changed rows, under the pace history writer's lock: the daily fit and the batch
     * UPDATE the same rows in chunks. A row is the attempt at its qualifying_count of its pair; the UPDATE goes by that
     * attempt's time - should the pace history be behind the solves (the fit between two batches), the next batch
     * syncs it and writes the rest.
     *
     * @return int rows written
     */
    private function writeDrift(PredictionEngine $engine): int
    {
        $set = $engine->set;

        $written = $this->paceHistoryWriter->underLock(function () use ($engine, $set): int {
            $changed = [];
            $written = 0;

            foreach ($engine->timeline->storedStates() as [$pairKey, $qualifyingCount, $drift, $spread]) {
                $i = $set->attemptAt($pairKey, $qualifyingCount);

                if ($i === null) {
                    continue;
                }

                $fresh = [$engine->driftAfter[$i], $engine->spreadAfter[$i]];

                if (self::differs($drift, $fresh[0]) || self::differs($spread, $fresh[1])) {
                    $changed[] = [$set->id[$i], $fresh[0], $fresh[1]];
                }

                if (count($changed) >= self::CHUNK) {
                    $written += $this->updateDrift($changed);
                    $changed = [];
                }
            }

            return $written + $this->updateDrift($changed);
        });

        $engine->timeline->forgetStoredStates();

        return $written;
    }

    /**
     * @param list<array{0: string, 1: null|float, 2: null|float}> $rows
     */
    private function updateDrift(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $this->connection->executeStatement('
            UPDATE player_pace_history h SET drift = fresh.drift, spread = fresh.spread
            FROM unnest(CAST(:times AS uuid[]), CAST(:drifts AS double precision[]), CAST(:spreads AS double precision[])) AS fresh (time_id, drift, spread)
            WHERE h.time_id = fresh.time_id
        ', [
            'times' => self::arrayLiteral(array_column($rows, 0)),
            'drifts' => self::arrayLiteral(array_column($rows, 1)),
            'spreads' => self::arrayLiteral(array_column($rows, 2)),
        ]);

        return count($rows);
    }

    private static function differs(null|float $stored, null|float $fresh): bool
    {
        if ($stored === null || $fresh === null) {
            return $stored !== $fresh;
        }

        return abs($stored - $fresh) > 1e-9;
    }

    /**
     * @param list<null|int|float|string> $values
     */
    private static function arrayLiteral(array $values): string
    {
        return '{' . implode(',', array_map(
            static fn (null|int|float|string $value): string => match (true) {
                $value === null => 'NULL',
                is_float($value) => var_export($value, true),
                default => '"' . $value . '"',
            },
            $values,
        )) . '}';
    }
}
