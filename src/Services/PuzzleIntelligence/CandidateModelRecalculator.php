<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AdjustedPace;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\AttemptFlags;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidateModelFitter;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePaceOffsets;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\FirstAttemptSet;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PredictionEngine;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PictureFactorsLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\PriorLookup;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\RepeatHistory;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\UnboxedFirstTries;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\CandidateFeatures;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\TasteState;

/**
 * The candidate prediction model's (v4, never shown - README "Step 8", "The candidate model (v4)") two jobs, each its
 * own command in its own process, so nothing of it can cost the shown model: not a failure, not the memory of the
 * batch container. Both read the shown model's state (its fit, its stored priors) and write only the candidate's tables.
 *
 * - fit() (`myspeedpuzzling:fit-candidate-prediction-model`, daily after the shown fit): every qualifying first attempt
 *   replayed like the shown fit does (its drift with its fitted tau), the candidate fitted on the last 12 months
 *   (CandidateModelFitter) into prediction_model_fit row CandidatePredictionCalculator::MODEL_VERSION, the candidate's
 *   brand prior of every puzzle (candidate_puzzle_prior), every puzzle's evidence (candidate_puzzle_evidence) and every
 *   player's taste state after each of their window's first tries (candidate_player_taste, rewritten);
 * - refreshEvidence() (`myspeedpuzzling:refresh-candidate-prediction-evidence`, every 15 minutes after the batch): every
 *   puzzle's evidence with the stored candidate fit and the drift the batch has just written - only the rows that
 *   changed. Nothing without a candidate fit.
 */
readonly final class CandidateModelRecalculator
{
    private const int CHUNK = 5000;

    public function __construct(
        private Connection $connection,
        private TimePredictionCalculator $calculator,
        private PredictionModelParametersProvider $parametersProvider,
        private PaceTimelineLoader $paceTimelineLoader,
        private PlayerBaselineCalculator $baselineCalculator,
        private CandidatePictureProjections $pictureProjections,
        private CandidateModelFitter $fitter = new CandidateModelFitter(),
    ) {
    }

    /**
     * In one transaction (a savepoint inside the handler's), the fitter's steps one by one, each structure released
     * once no later step reads it (the container has 512 MB): the brand prior's pseudo-count (then the candidate's brand
     * priors are written), the samples as the shown model sees them (then the shown as-of difficulty, the priors and the
     * image estimates are released), the index noise (then every puzzle's evidence is written), the size curve, the
     * samples' evidence (then the attempt set and the engines are released), the rest on the samples alone. The priors
     * and the evidence rest only on the pseudo-count and the noise, which nothing later changes.
     *
     * @return array{candidate: CandidateModelParameters, priors_written: int, evidence_written: int, taste_states_written: int, pace_offsets_written: int, picture_projections_written: int}
     */
    public function fit(DateTimeImmutable $now): array
    {
        return $this->connection->transactional(function () use ($now): array {
            $shown = $this->parametersProvider->current();
            $from = (float) $now->modify('-' . PredictionModelRecalculator::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
            [$engine, $priors] = $this->replay($now, fittedPriors: true, tau: $shown->tau);
            $candidate = new CandidateEngine($engine, AttemptFlags::load($this->connection, $this->baselineCalculator, $engine->set));
            // Nothing reads the ids after the flags
            $engine->set->forgetIds();
            unset($engine);

            $progress = $this->fitter->begin($candidate, $priors, $from, (float) $now->getTimestamp(), $shown, CandidateFeatures::kept());
            $progress->previousSizeCurve = $this->parametersProvider->candidate()?->sizeCurve;
            $priorsWritten = $this->writePriors($priors, $progress->parameters, $now);
            $progress->lap('priors_written');

            $imageEstimates = $this->imageEstimates($now);
            $this->fitter->sample($progress, $candidate, $priors, $imageEstimates, UnboxedFirstTries::load($this->connection));
            $candidate->engine->releaseAsOfDifficulty();
            unset($priors, $imageEstimates);

            $progress->lap('shown_released');

            $this->fitter->fitNoise($progress, $candidate);
            $this->fitter->addEvidence($progress, $candidate);
            // v7: the difficulty-adjusted pace (every puzzle's difficulty now, the paces from it, the indices again) -
            // the evidence is written from the adjusted indices, the offsets for the live and reconstructed paths
            $this->fitter->adjustPace($progress, $candidate);
            $offsetsWritten = $this->writePaceOffsets($candidate, $now);
            $evidenceWritten = $this->writeEvidence($candidate, $progress->parameters, $now);
            $progress->lap('evidence_written');

            $this->fitter->fitSizeCurve($progress, $candidate);

            unset($candidate);
            $progress->lap('released');

            // The taste factor (finish()): the pictures' factors, and every player's state after each of their window's
            // first tries stored as it comes (candidate_player_taste is rewritten: W changes every day)
            $progress->pictures = PictureFactorsLookup::load($this->connection, $now);
            $this->connection->executeStatement('DELETE FROM candidate_player_taste');
            $tasteStates = [];
            $tasteWritten = 0;
            $progress->tasteStates = function (string $player, float $solved, float $tracked, TasteState $state) use (&$tasteStates, &$tasteWritten, $now): void {
                // The same player at the same moment twice (two times saved in one second): the state after both
                $tasteStates[$player . '|' . $solved . '|' . $tracked] = [$player, $solved, $tracked, $state];

                if (count($tasteStates) >= self::CHUNK) {
                    $tasteWritten += $this->writeTasteStates($tasteStates, $now);
                    $tasteStates = [];
                }
            };

            // Loaded only for the repeats' step, after the first tries' samples are gone
            $parameters = $this->fitter->finish($progress, fn (): RepeatHistory => RepeatHistory::load($this->connection, $from), $now);
            $tasteWritten += $this->writeTasteStates($tasteStates, $now);
            unset($tasteStates);

            $this->connection->executeStatement('
                INSERT INTO prediction_model_fit (model_version, fitted_at, parameters) VALUES (:version, :now, CAST(:parameters AS jsonb))
                ON CONFLICT (model_version) DO UPDATE SET fitted_at = EXCLUDED.fitted_at, parameters = EXCLUDED.parameters
            ', [
                'version' => CandidatePredictionCalculator::MODEL_VERSION,
                'now' => $now->format('Y-m-d H:i:s'),
                'parameters' => json_encode($parameters->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            ]);

            // The pictures' taste projections with the new W (the shown v7 Picker's SQL - CandidatePictureProjections)
            $projectionsWritten = $this->pictureProjections->sync($parameters, $now, $now);

            return ['candidate' => $parameters, 'priors_written' => $priorsWritten, 'evidence_written' => $evidenceWritten, 'taste_states_written' => $tasteWritten, 'pace_offsets_written' => $offsetsWritten, 'picture_projections_written' => $projectionsWritten];
        });
    }

    /**
     * v7: every pace row's difficulty-adjusted offset (AdjustedPace, quantised) into candidate_pace_offset, rewritten - a
     * row only where the pair's offset moved (AdjustedPace::STEP; the readers take the latest row before a moment). None
     * while the fit has the adjusted pace off.
     */
    private function writePaceOffsets(CandidateEngine $candidate, DateTimeImmutable $now): int
    {
        $this->connection->executeStatement('DELETE FROM candidate_pace_offset');
        $adjusted = $candidate->adjustedPace;

        if ($adjusted === null) {
            return 0;
        }

        $set = $candidate->engine->set;
        $rows = [];
        $written = 0;
        $lastPair = null;
        $lastOffset = null;

        foreach ($adjusted->rows() as [$i, $offset]) {
            $pairKey = $set->player[$i] . '|' . $set->pieces[$i];

            // Quantised by AdjustedPace already: a row only where the pair's offset moved
            if ($pairKey === $lastPair && $offset === $lastOffset) {
                continue;
            }

            $lastPair = $pairKey;
            $lastOffset = $offset;
            // Two first tries of the pair in one second: the row after both (the later one overwrites)
            $rows[$pairKey . '|' . $set->solved[$i] . '|' . $set->tracked[$i]] = [$set->player[$i], $set->pieces[$i], $set->solved[$i], $set->tracked[$i], $offset];

            if (count($rows) >= self::CHUNK) {
                $written += $this->insertPaceOffsets($rows, $now);
                $rows = [];
            }
        }

        return $written + $this->insertPaceOffsets($rows, $now);
    }

    /**
     * @param array<string, array{0: string, 1: int, 2: float, 3: float, 4: float}> $rows
     */
    private function insertPaceOffsets(array $rows, DateTimeImmutable $now): int
    {
        if ($rows === []) {
            return 0;
        }

        $rows = array_values($rows);
        $this->connection->executeStatement('
            INSERT INTO candidate_pace_offset (player_id, pieces_count, valid_from, valid_from_tracked_at, pace_offset, computed_at)
            SELECT fresh.player_id, fresh.pieces_count, fresh.valid_from, fresh.valid_from_tracked_at, fresh.pace_offset, CAST(:now AS timestamp)
            FROM unnest(CAST(:players AS uuid[]), CAST(:pieces AS int[]), CAST(:solved AS timestamp[]), CAST(:tracked AS timestamp[]), CAST(:offsets AS double precision[]))
                AS fresh (player_id, pieces_count, valid_from, valid_from_tracked_at, pace_offset)
            ON CONFLICT (player_id, pieces_count, valid_from, valid_from_tracked_at) DO UPDATE SET pace_offset = EXCLUDED.pace_offset, computed_at = EXCLUDED.computed_at
        ', [
            'now' => $now->format('Y-m-d H:i:s'),
            'players' => self::arrayLiteral(array_map(static fn (array $row): string => $row[0], $rows)),
            'pieces' => self::arrayLiteral(array_map(static fn (array $row): int => $row[1], $rows)),
            'solved' => self::arrayLiteral(array_map(static fn (array $row): string => gmdate('Y-m-d H:i:s', (int) $row[2]), $rows)),
            'tracked' => self::arrayLiteral(array_map(static fn (array $row): string => gmdate('Y-m-d H:i:s', (int) $row[3]), $rows)),
            'offsets' => self::arrayLiteral(array_map(static fn (array $row): float => $row[4], $rows)),
        ]);

        return count($rows);
    }

    /**
     * Players' taste states (TasteState) as of each first try - inserted in one statement; a key already written (two
     * first tries in one second, split by a chunk) takes the later state.
     *
     * @param array<string, array{0: string, 1: float, 2: float, 3: TasteState}> $states
     */
    private function writeTasteStates(array $states, DateTimeImmutable $now): int
    {
        if ($states === []) {
            return 0;
        }

        $states = array_values($states);
        $this->connection->executeStatement('
            INSERT INTO candidate_player_taste (player_id, valid_from, valid_from_tracked_at, first_tries, state, computed_at)
            SELECT fresh.player_id, fresh.valid_from, fresh.valid_from_tracked_at, fresh.first_tries, fresh.state, CAST(:now AS timestamp)
            FROM unnest(CAST(:players AS uuid[]), CAST(:solved AS timestamp[]), CAST(:tracked AS timestamp[]), CAST(:firstTries AS int[]), CAST(:states AS jsonb[]))
                AS fresh (player_id, valid_from, valid_from_tracked_at, first_tries, state)
            ON CONFLICT (player_id, valid_from, valid_from_tracked_at) DO UPDATE SET first_tries = EXCLUDED.first_tries, state = EXCLUDED.state, computed_at = EXCLUDED.computed_at
        ', [
            'now' => $now->format('Y-m-d H:i:s'),
            'players' => self::arrayLiteral(array_map(static fn (array $state): string => $state[0], $states)),
            'solved' => self::arrayLiteral(array_map(static fn (array $state): string => gmdate('Y-m-d H:i:s', (int) $state[1]), $states)),
            'tracked' => self::arrayLiteral(array_map(static fn (array $state): string => gmdate('Y-m-d H:i:s', (int) $state[2]), $states)),
            'firstTries' => self::arrayLiteral(array_map(static fn (array $state): int => $state[3]->firstTries, $states)),
            'states' => self::arrayLiteral(array_map(static fn (array $state): string => json_encode($state[3]->toArray(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION), $states)),
        ]);

        return count($states);
    }

    /**
     * @return array{evidence_written: int, fitted: bool, picture_projections_written?: int}
     */
    public function refreshEvidence(DateTimeImmutable $now): array
    {
        $parameters = $this->parametersProvider->candidate();

        if ($parameters === null) {
            return ['evidence_written' => 0, 'fitted' => false];
        }

        [$engine] = $this->replay($now, fittedPriors: false, tau: $this->parametersProvider->current()->tau);
        // The flags weigh indices only through parts the fit may have switched off - no statement for them then
        $flags = $parameters->features()->flagMask() !== 0
            ? AttemptFlags::load($this->connection, $this->baselineCalculator, $engine->set)
            : AttemptFlags::fromList([]);

        $candidate = new CandidateEngine($engine, $flags);

        // v7: the indices read the solvers' adjusted paces as the daily fit stored them (a pace row since: the latest)
        if ($parameters->features()->on('adjusted_pace')) {
            $candidate->adjustedPace = AdjustedPace::fromStored($engine->set, CandidatePaceOffsets::load($this->connection));
        }

        $evidenceWritten = $this->writeEvidence($candidate, $parameters, $now);
        unset($candidate, $engine);
        $fittedAt = $this->parametersProvider->candidateFittedAt();

        return [
            'evidence_written' => $evidenceWritten,
            'fitted' => true,
            // Estimates pushed since the last run (the shown v7 Picker's SQL - CandidatePictureProjections)
            'picture_projections_written' => $fittedAt !== null ? $this->pictureProjections->sync($parameters, $fittedAt, $now) : 0,
        ];
    }

    /**
     * Every qualifying first attempt as of its moment with the drift and spread of the shown model: with the priors the
     * shown fit fits (the daily fit) or with the ones it stored (the 15-minute refresh, like the batch's drift).
     *
     * @return array{0: PredictionEngine, 1: PriorLookup}
     */
    private function replay(DateTimeImmutable $now, bool $fittedPriors, float $tau): array
    {
        $timeline = $this->paceTimelineLoader->all();
        // Through a cursor: the whole result in the client library next to the arrays built from it cost ~80 MB
        [$sql, $params, $types] = $this->baselineCalculator->qualifyingFirstAttemptsStatement(paceOrder: true);
        /** @var iterable<array{id: string, player_id: string, puzzle_id: string, pieces_count: int|string, seconds_to_solve: int|string, solved_ts: float|int|string, tracked_ts: float|int|string}> $rows */
        $rows = CursorRows::iterate($this->connection, $sql, $params, $types);
        $set = FirstAttemptSet::build($rows, $timeline);
        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();
        $manufacturers = $this->manufacturerByPuzzle($now);
        $priors = $fittedPriors ? PriorLookup::fit($set, $manufacturers) : $this->storedPriors($manufacturers);
        $engine->computeDrift($priors, $tau);
        gc_mem_caches();

        return [$engine, $priors];
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
     * The shown model's stored prior sums - what its batch computes the drift with.
     *
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
     * The estimate of every puzzle's current picture (the shown fit's rule) - none for a secret puzzle.
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

        // One string per model name and version (tens of thousands of rows, a handful of models)
        $strings = [];

        /** @var array<string, mixed> $row */
        foreach ($rows as $row) {
            foreach (['image_model', 'image_model_version'] as $key) {
                if (is_string($row[$key] ?? null)) {
                    $row[$key] = $strings[$row[$key]] ??= $row[$key];
                }
            }

            $estimate = ImagePriorInput::fromRow($row);

            if ($estimate !== null && is_string($row['id'])) {
                $estimates[$row['id']] = $estimate;
            }
        }

        return $estimates;
    }

    /**
     * Every puzzle's candidate brand prior (PuzzleWeightedBrandPrior with the fitted pseudo-count) in one transaction;
     * rows of puzzles without one are removed.
     */
    private function writePriors(PriorLookup $priors, CandidateModelParameters $parameters, DateTimeImmutable $now): int
    {
        $values = [];

        foreach ($this->connection->iterateKeyValue('SELECT id, pieces_count FROM puzzle') as $puzzleId => $piecesCount) {
            /** @var string $puzzleId */
            /** @var int|string $piecesCount */
            $prior = $priors->puzzleWeightedBrandPrior($puzzleId, (int) $piecesCount, $parameters->brandPseudoPuzzles);

            if ($prior !== null) {
                $values[$puzzleId] = $prior;
            }
        }

        $this->connection->transactional(function (Connection $connection) use ($values, $now): void {
            foreach (array_chunk(array_keys($values), self::CHUNK) as $chunk) {
                $connection->executeStatement('
                    INSERT INTO candidate_puzzle_prior (puzzle_id, brand_prior_log, computed_at)
                    SELECT fresh.puzzle_id, fresh.brand_prior_log, CAST(:now AS timestamp)
                    FROM unnest(CAST(:puzzles AS uuid[]), CAST(:priors AS double precision[])) AS fresh (puzzle_id, brand_prior_log)
                    ON CONFLICT (puzzle_id) DO UPDATE SET brand_prior_log = EXCLUDED.brand_prior_log, computed_at = EXCLUDED.computed_at
                    WHERE candidate_puzzle_prior.brand_prior_log IS DISTINCT FROM EXCLUDED.brand_prior_log
                ', [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'puzzles' => self::arrayLiteral(array_map('strval', $chunk)),
                    'priors' => self::arrayLiteral(array_map(static fn (int|string $puzzle): float => $values[$puzzle], $chunk)),
                ]);
            }

            $connection->executeStatement(
                'DELETE FROM candidate_puzzle_prior WHERE NOT (puzzle_id = ANY (CAST(:puzzles AS uuid[])))',
                ['puzzles' => self::arrayLiteral(array_map('strval', array_keys($values)))],
            );
        });

        return count($values);
    }

    /**
     * Every puzzle's candidate evidence now (every index), written where it changed; rows of puzzles without an index
     * any more are removed. A genuine bulk operation: set-based SQL.
     *
     * @return int rows written or removed
     */
    private function writeEvidence(CandidateEngine $candidate, CandidateModelParameters $parameters, DateTimeImmutable $now): int
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
        unset($stored, $present);

        foreach (array_chunk(array_keys($changed), self::CHUNK) as $chunk) {
            $chunk = array_map('strval', $chunk);
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

    private static function differs(float $stored, float $fresh): bool
    {
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
                is_int($value) => (string) $value,
                default => '"' . addcslashes((string) $value, '"\\') . '"',
            },
            $values,
        )) . '}';
    }
}
