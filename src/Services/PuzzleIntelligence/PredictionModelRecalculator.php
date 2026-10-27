<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\RepeatHistory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\UnboxedFirstTries;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
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
 *
 * The candidate model (v4, never shown - README "Step 8", "The candidate model (v4)") rides on both: the daily fit fits
 * it after the shown model (CandidateModelFitter, prediction_model_fit row CandidatePredictionCalculator::MODEL_VERSION)
 * and stores every puzzle's candidate brand prior (difficulty_prior_puzzle.candidate_prior_log); both write every
 * puzzle's candidate evidence (candidate_puzzle_evidence) - the batch only once a candidate fit exists. Nothing of the
 * shown model changes with it.
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
        private ClockInterface $clock,
        private CandidateModelFitter $candidateFitter = new CandidateModelFitter(),
    ) {
    }

    /**
     * The batch's step: drift and spread with the stored fit (defaults and no priors before the first fit). The timeline
     * comes with the rows' stored drift and spread (PaceTimelineLoader::all(withStoredStates: true)).
     *
     * @return array{drift_rows_written: int, candidate_evidence_written: int, candidate_seconds: float}
     */
    public function recalculate(FirstAttemptSet $set, PaceTimeline $timeline): array
    {
        $parameters = $this->parametersProvider->current();
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();
        $engine->computeDrift($this->storedPriors($this->manufacturerByPuzzle($this->clock->now())), $parameters->tau);
        $driftWritten = $this->writeDrift($engine);

        $startedAt = microtime(true);
        $candidate = $this->parametersProvider->candidate();
        // The flags weigh indices only through parts the fit may have switched off - no statement for them then
        $evidenceWritten = $candidate !== null
            ? $this->writeCandidateEvidence($this->candidateEngine($engine, withFlags: $candidate->features()->flagMask() !== 0), $candidate, $this->clock->now())
            : 0;

        return ['drift_rows_written' => $driftWritten, 'candidate_evidence_written' => $evidenceWritten, 'candidate_seconds' => round(microtime(true) - $startedAt, 2)];
    }

    /**
     * The daily fit on everything as it is now, then the drift and spread with it.
     *
     * @return array{prior_groups: int, prior_puzzles: int, priors_seconds: float, drift_rows_written: int, parameters: PredictionModelParameters, candidate: CandidateModelParameters, candidate_evidence_written: int}
     */
    public function fit(DateTimeImmutable $now): array
    {
        $timeline = $this->paceTimelineLoader->all(withStoredStates: true);
        $set = FirstAttemptSet::build($this->baselineCalculator->iterateQualifyingFirstAttempts(paceOrder: true), $timeline);
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();

        $priorsStartedAt = microtime(true);
        $priors = PriorLookup::fit($set, $this->manufacturerByPuzzle($now));
        $imageEstimates = $this->imageEstimates($now);
        $priorsSeconds = microtime(true) - $priorsStartedAt;

        $from = (float) $now->modify('-' . self::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
        $to = (float) $now->getTimestamp();
        $repeats = RepeatHistory::load($this->connection, $from);
        // Fitting tau computes the drift with it (PredictionModelFitter step 2)
        $parameters = $this->fitter->fit($engine, $priors, $repeats, $from, $to, $now, $imageEstimates);
        $this->storeParameters(TimePredictionCalculator::MODEL_VERSION, $parameters->toArray(), $now);

        // The drift written first: that frees the timeline's stored states before the candidate needs its memory
        $driftWritten = $this->writeDrift($engine);
        gc_collect_cycles();
        gc_mem_caches();

        // The candidate after the shown model: it shares its drift, spread and thin-size rules
        $memoryBeforeCandidate = round(memory_get_peak_usage(true) / 1048576);
        $candidateStartedAt = microtime(true);
        $candidateEngine = $this->candidateEngine($engine);
        $candidate = $this->candidateFitter->fit($candidateEngine, $priors, $repeats, $from, $to, $now, $imageEstimates, $parameters, CandidateFeatures::kept(), UnboxedFirstTries::load($this->connection));
        $candidate = $candidate->with(summary: [
            ...$candidate->summary,
            'seconds_total' => round(microtime(true) - $candidateStartedAt, 1),
            'memory_peak_before_mb' => $memoryBeforeCandidate,
        ]);
        $this->storeParameters(CandidatePredictionCalculator::MODEL_VERSION, $candidate->toArray(), $now);

        $priorsStartedAt = microtime(true);
        $priorRows = $this->puzzlePriors($priors, $imageEstimates, $candidate);
        $this->writePriors($priors, $priorRows, $now);
        $priorsSeconds += microtime(true) - $priorsStartedAt;

        return [
            'prior_groups' => count($priors->groupSums),
            'prior_puzzles' => count($priorRows),
            'priors_seconds' => round($priorsSeconds, 2),
            'drift_rows_written' => $driftWritten,
            'parameters' => $parameters,
            'candidate' => $candidate,
            'candidate_evidence_written' => $this->writeCandidateEvidence($candidateEngine, $candidate, $now),
        ];
    }

    private function candidateEngine(PredictionEngine $engine, bool $withFlags = true): CandidateEngine
    {
        return new CandidateEngine($engine, $withFlags ? AttemptFlags::load($this->connection, $this->baselineCalculator, $engine->set) : AttemptFlags::fromList([]));
    }

    /**
     * Every puzzle's candidate evidence as of now (every index), written where it changed; rows of puzzles without an
     * index any more are removed. A genuine bulk operation like the drift: set-based SQL.
     *
     * @return int rows written or removed
     */
    private function writeCandidateEvidence(CandidateEngine $candidate, CandidateModelParameters $parameters, DateTimeImmutable $now): int
    {
        $stored = [];

        foreach ($this->connection->iterateNumeric('SELECT puzzle_id, index_count, weight_sum, weighted_log_sum, log_sum, log_square_sum, noise_sum FROM candidate_puzzle_evidence') as $row) {
            /** @var array{0: string, 1: int|string, 2: float|string, 3: float|string, 4: float|string, 5: float|string, 6: float|string} $row */
            $stored[$row[0]] = new CandidateEvidence((int) $row[1], (float) $row[2], (float) $row[3], (float) $row[4], (float) $row[5], (float) $row[6]);
        }

        $changed = [];
        $present = [];

        foreach ($candidate->engine->set->puzzles() as $puzzle) {
            $evidence = $candidate->evidenceNow($puzzle, $parameters);

            if ($evidence->k === 0) {
                continue;
            }

            $present[$puzzle] = true;
            $old = $stored[$puzzle] ?? null;

            if (
                $old === null || $old->k !== $evidence->k || self::differs($old->weightSum, $evidence->weightSum) || self::differs($old->weightedLogSum, $evidence->weightedLogSum)
                || self::differs($old->logSum, $evidence->logSum) || self::differs($old->logSquareSum, $evidence->logSquareSum) || self::differs($old->noiseSum, $evidence->noiseSum)
            ) {
                $changed[$puzzle] = $evidence;
            }
        }

        $removed = array_values(array_filter(array_keys($stored), static fn (string $puzzle): bool => !isset($present[$puzzle])));

        foreach (array_chunk(array_keys($changed), self::CHUNK) as $chunk) {
            $this->connection->executeStatement('
                INSERT INTO candidate_puzzle_evidence (puzzle_id, index_count, weight_sum, weighted_log_sum, log_sum, log_square_sum, noise_sum, computed_at)
                SELECT fresh.puzzle_id, fresh.index_count, fresh.weight_sum, fresh.weighted_log_sum, fresh.log_sum, fresh.log_square_sum, fresh.noise_sum, CAST(:now AS timestamp)
                FROM unnest(CAST(:puzzles AS uuid[]), CAST(:counts AS int[]), CAST(:weights AS double precision[]), CAST(:weightedLogs AS double precision[]),
                    CAST(:logs AS double precision[]), CAST(:squares AS double precision[]), CAST(:noises AS double precision[]))
                    AS fresh (puzzle_id, index_count, weight_sum, weighted_log_sum, log_sum, log_square_sum, noise_sum)
                ON CONFLICT (puzzle_id) DO UPDATE SET
                    index_count = EXCLUDED.index_count,
                    weight_sum = EXCLUDED.weight_sum,
                    weighted_log_sum = EXCLUDED.weighted_log_sum,
                    log_sum = EXCLUDED.log_sum,
                    log_square_sum = EXCLUDED.log_square_sum,
                    noise_sum = EXCLUDED.noise_sum,
                    computed_at = EXCLUDED.computed_at
            ', [
                'now' => $now->format('Y-m-d H:i:s'),
                'puzzles' => self::arrayLiteral($chunk),
                'counts' => self::arrayLiteral(array_map(static fn (string $puzzle): int => $changed[$puzzle]->k, $chunk)),
                'weights' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $changed[$puzzle]->weightSum, $chunk)),
                'weightedLogs' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $changed[$puzzle]->weightedLogSum, $chunk)),
                'logs' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $changed[$puzzle]->logSum, $chunk)),
                'squares' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $changed[$puzzle]->logSquareSum, $chunk)),
                'noises' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $changed[$puzzle]->noiseSum, $chunk)),
            ]);
        }

        foreach (array_chunk($removed, self::CHUNK) as $chunk) {
            $this->connection->executeStatement(
                'DELETE FROM candidate_puzzle_evidence WHERE puzzle_id = ANY (CAST(:puzzles AS uuid[]))',
                ['puzzles' => self::arrayLiteral($chunk)],
            );
        }

        return count($changed) + count($removed);
    }

    /**
     * @return array<string, null|string>
     */
    private function manufacturerByPuzzle(DateTimeImmutable $now): array
    {
        /** @var array<string, null|string> */
        return $this->connection->fetchAllKeyValue(
            'SELECT id, CASE WHEN ' . StatisticalInputsSql::NOT_SECRET . ' THEN manufacturer_id END FROM puzzle p',
            ['now' => $now->format('Y-m-d H:i:s')],
        );
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
     * - candidate_prior_log - the candidate model's puzzle-weighted brand × pieces prior (PuzzleWeightedBrandPrior).
     *
     * @param array<string, ImagePriorInput> $imageEstimates imageEstimates()
     * @return array<string, array{0: float, 1: int, 2: null|float, 3: null|float, 4: null|float}> puzzle => [share sum, share count, prior log, blend prior log, candidate prior log]
     */
    private function puzzlePriors(PriorLookup $priors, array $imageEstimates, CandidateModelParameters $candidate): array
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
            $candidatePrior = $priors->puzzleWeightedBrandPrior($puzzleId, (int) $piecesCount, $candidate->brandPseudoPuzzles);

            if ($share !== null || $prior !== null || $blendPrior !== null || $candidatePrior !== null) {
                $rows[$puzzleId] = [$share[0] ?? 0.0, $share[1] ?? 0, $prior, $blendPrior, $candidatePrior];
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
    private function imageEstimates(DateTimeImmutable $now): array
    {
        $estimates = [];
        $rows = $this->connection->iterateAssociative('
            SELECT p.id, ' . StatisticalInputsSql::IMAGE_ESTIMATE_COLUMNS . '
            FROM puzzle p
            ' . StatisticalInputsSql::imageEstimateJoins(outOfFoldOnly: false) . '
            WHERE img.puzzle_id IS NOT NULL
        ', ['now' => $now->format('Y-m-d H:i:s')]);

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
     * @param array<string, array{0: float, 1: int, 2: null|float, 3: null|float, 4: null|float}> $puzzleRows puzzlePriors()
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
                    INSERT INTO difficulty_prior_puzzle (puzzle_id, sum_log_indices, index_count, prior_log, blend_prior_log, candidate_prior_log, computed_at)
                    SELECT fresh.puzzle_id, fresh.sum_log_indices, fresh.index_count, fresh.prior_log, fresh.blend_prior_log, fresh.candidate_prior_log, CAST(:now AS timestamp)
                    FROM unnest(CAST(:puzzles AS uuid[]), CAST(:sums AS double precision[]), CAST(:counts AS int[]), CAST(:priors AS double precision[]), CAST(:blendPriors AS double precision[]), CAST(:candidatePriors AS double precision[]))
                        AS fresh (puzzle_id, sum_log_indices, index_count, prior_log, blend_prior_log, candidate_prior_log)
                    ON CONFLICT (puzzle_id) DO UPDATE SET
                        sum_log_indices = EXCLUDED.sum_log_indices,
                        index_count = EXCLUDED.index_count,
                        prior_log = EXCLUDED.prior_log,
                        blend_prior_log = EXCLUDED.blend_prior_log,
                        candidate_prior_log = EXCLUDED.candidate_prior_log,
                        computed_at = EXCLUDED.computed_at
                    WHERE (difficulty_prior_puzzle.sum_log_indices, difficulty_prior_puzzle.index_count, difficulty_prior_puzzle.prior_log, difficulty_prior_puzzle.blend_prior_log, difficulty_prior_puzzle.candidate_prior_log)
                        IS DISTINCT FROM (EXCLUDED.sum_log_indices, EXCLUDED.index_count, EXCLUDED.prior_log, EXCLUDED.blend_prior_log, EXCLUDED.candidate_prior_log)
                ', [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'puzzles' => self::arrayLiteral($chunk),
                    'sums' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $puzzleRows[$puzzle][0], $chunk)),
                    'counts' => self::arrayLiteral(array_map(static fn (string $puzzle): int => $puzzleRows[$puzzle][1], $chunk)),
                    'priors' => self::arrayLiteral(array_map(static fn (string $puzzle): null|float => $puzzleRows[$puzzle][2], $chunk)),
                    'blendPriors' => self::arrayLiteral(array_map(static fn (string $puzzle): null|float => $puzzleRows[$puzzle][3], $chunk)),
                    'candidatePriors' => self::arrayLiteral(array_map(static fn (string $puzzle): null|float => $puzzleRows[$puzzle][4], $chunk)),
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

    /**
     * @param array<string, mixed> $data the fit as stored (PredictionModelParameters / CandidateModelParameters::toArray())
     */
    private function storeParameters(int $modelVersion, array $data, DateTimeImmutable $now): void
    {
        $this->connection->executeStatement('
            INSERT INTO prediction_model_fit (model_version, fitted_at, parameters) VALUES (:version, :now, CAST(:parameters AS jsonb))
            ON CONFLICT (model_version) DO UPDATE SET fitted_at = EXCLUDED.fitted_at, parameters = EXCLUDED.parameters
        ', [
            'version' => $modelVersion,
            'now' => $now->format('Y-m-d H:i:s'),
            'parameters' => json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
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
