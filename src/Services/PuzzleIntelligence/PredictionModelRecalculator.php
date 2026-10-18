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
use SpeedPuzzling\Web\Value\PredictionModelParameters;

/**
 * The prediction model's step of the insights batch (README "Step 8: Time Prediction", "The model's batch step"),
 * a genuine bulk operation over every pace-history row - set-based SQL like the rest of the batch:
 *
 * - once a day (FIT_EVERY_HOURS): BrandPiecesPrior's groups and every puzzle's share in them, then the rest of the
 *   model (PredictionModelFitter, the last FIT_WINDOW_MONTHS) into prediction_model_fit;
 * - every run: the drift and spread of every player_pace_history row, replayed with PredictionEngine over all
 *   qualifying first attempts - only the rows whose values changed are written.
 */
readonly final class PredictionModelRecalculator
{
    public const int FIT_EVERY_HOURS = 20;
    public const int FIT_WINDOW_MONTHS = 12;

    private const int CHUNK = 5000;

    public function __construct(
        private Connection $connection,
        private TimePredictionCalculator $calculator,
        private PredictionModelFitter $fitter,
        private PredictionModelParametersProvider $parametersProvider,
    ) {
    }

    /**
     * @return array{fitted: bool, prior_groups: int, drift_rows_written: int}
     */
    public function recalculate(FirstAttemptSet $set, PaceTimeline $timeline, DateTimeImmutable $now, bool $forceFit = false): array
    {
        $stored = $this->parametersProvider->current();
        $fitDue = $forceFit
            || $stored->fittedAt === null
            || $stored->fittedAt < $now->modify('-' . self::FIT_EVERY_HOURS . ' hours');

        $engine = new PredictionEngine($set, $timeline, $this->calculator);
        $engine->computeAsOfDifficulty();
        $manufacturers = $this->manufacturerByPuzzle();

        if ($fitDue) {
            $priors = PriorLookup::fit($set, $manufacturers);
            $this->writePriors($priors, $now);

            $from = (float) $now->modify('-' . self::FIT_WINDOW_MONTHS . ' months')->getTimestamp();
            $parameters = $this->fitter->fit(
                $engine,
                $priors,
                RepeatHistory::load($this->connection, $from),
                $from,
                (float) $now->getTimestamp(),
                $now,
            );
            $this->storeParameters($parameters, $now);
        } else {
            $priors = $this->storedPriors($manufacturers);
            $engine->computeDrift($priors, $stored->tau);
        }

        return [
            'fitted' => $fitDue,
            'prior_groups' => count($priors->groupSums),
            'drift_rows_written' => $this->writeDrift($engine),
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
     * Groups and shares change together (one transaction): a prediction never sees new sums with old shares.
     */
    private function writePriors(PriorLookup $priors, DateTimeImmutable $now): void
    {
        $this->connection->transactional(function (Connection $connection) use ($priors, $now): void {
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

            $puzzles = array_keys($priors->puzzleShares);

            foreach (array_chunk($puzzles, self::CHUNK) as $chunk) {
                $connection->executeStatement('
                    INSERT INTO difficulty_prior_puzzle (puzzle_id, sum_log_indices, index_count, computed_at)
                    SELECT fresh.puzzle_id, fresh.sum_log_indices, fresh.index_count, CAST(:now AS timestamp)
                    FROM unnest(CAST(:puzzles AS uuid[]), CAST(:sums AS double precision[]), CAST(:counts AS int[])) AS fresh (puzzle_id, sum_log_indices, index_count)
                    ON CONFLICT (puzzle_id) DO UPDATE SET
                        sum_log_indices = EXCLUDED.sum_log_indices,
                        index_count = EXCLUDED.index_count,
                        computed_at = EXCLUDED.computed_at
                    WHERE (difficulty_prior_puzzle.sum_log_indices, difficulty_prior_puzzle.index_count)
                        IS DISTINCT FROM (EXCLUDED.sum_log_indices, EXCLUDED.index_count)
                ', [
                    'now' => $now->format('Y-m-d H:i:s'),
                    'puzzles' => self::arrayLiteral($chunk),
                    'sums' => self::arrayLiteral(array_map(static fn (string $puzzle): float => $priors->puzzleShares[$puzzle][0], $chunk)),
                    'counts' => self::arrayLiteral(array_map(static fn (string $puzzle): int => $priors->puzzleShares[$puzzle][1], $chunk)),
                ]);
            }

            $connection->executeStatement(
                'DELETE FROM difficulty_prior_puzzle WHERE NOT (puzzle_id = ANY (CAST(:puzzles AS uuid[])))',
                ['puzzles' => self::arrayLiteral($puzzles)],
            );
        });
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
     * Streams the stored rows (no map of them in memory): a row is the attempt at its qualifying_count of its pair.
     *
     * @return int rows written
     */
    private function writeDrift(PredictionEngine $engine): int
    {
        $set = $engine->set;
        $changed = [];
        $written = 0;
        /** @var null|array<string, int> $byId built only if a row is not where it should be */
        $byId = null;

        foreach ($this->connection->iterateNumeric('SELECT time_id, player_id, pieces_count, qualifying_count, drift, spread FROM player_pace_history') as $row) {
            /** @var array{0: string, 1: string, 2: int|string, 3: int|string, 4: null|float|string, 5: null|float|string} $row */
            [$timeId, $playerId, $piecesCount, $qualifyingCount, $drift, $spread] = $row;
            $i = $set->attemptAt($playerId . '|' . $piecesCount, (int) $qualifyingCount);

            if ($i === null || $set->id[$i] !== $timeId) {
                $byId ??= array_flip($set->id);
                $i = $byId[$timeId] ?? null;
            }

            if ($i === null) {
                continue;
            }

            $fresh = [$engine->driftAfter[$i], $engine->spreadAfter[$i]];

            if (self::differs($drift !== null ? (float) $drift : null, $fresh[0]) || self::differs($spread !== null ? (float) $spread : null, $fresh[1])) {
                $changed[] = [$timeId, $fresh[0], $fresh[1]];
            }

            if (count($changed) >= self::CHUNK) {
                $written += $this->updateDrift($changed);
                $changed = [];
            }
        }

        return $written + $this->updateDrift($changed);
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
