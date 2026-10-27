<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence\Group;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\GroupCoordination;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\TimePredictionMethod;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * Writes the pair/team predictions of a replay into solving_time_prediction (predictors `group` and `group_baseline`,
 * GroupPredictionModel::MODEL_VERSION) and what they were made for into group_prediction_input. A genuine bulk
 * operation (CLAUDE.md "The write model works with PHP objects" - the exception): ~75k times on the first fill, a few
 * hundred a day afterwards, never loaded as entities - multi-row upserts of BATCH times, the rows' columns exactly the
 * ones SolvingTimePredictionRecord maps. Touches no row of another predictor and no column of puzzle_solving_time.
 */
readonly final class GroupPredictionRowsWriter
{
    public const int BATCH = 500;

    /** Only times without rows, or whose fingerprint changed (the daily run with yesterday's fit) */
    public const string CHANGED = 'changed';
    /** Also every reconstructed row (after a refit that should replace them - the first fill, --recompute) */
    public const string RECONSTRUCTED = 'reconstructed';

    private const array PREDICTION_COLUMNS = ['id', 'time_id', 'predictor', 'model_version', 'source', 'predictable', 'method', 'predicted_seconds', 'range50_low', 'range50_high', 'range80_low', 'range80_high', 'difficulty_used', 'difficulty_source', 'k_indices', 'pace_source', 'prior_log', 'window_bucket', 'image_model', 'image_model_version', 'image_kind', 'computed_at'];

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @param array<string, array{0: string, 1: bool}> $stored time id => fingerprint, live (GetGroupPredictionInputs)
     * @param null|DateTimeImmutable $fittedAt when the replay's fit was made: a time first predicted after it was
     *                                         tracked later than that is a live (out-of-sample) row
     * @return int times written
     */
    public function write(GroupResultSet $set, GroupReplay $replay, array $stored, string $mode, null|DateTimeImmutable $fittedAt, DateTimeImmutable $now): int
    {
        $batch = [];
        $written = 0;
        $fittedTs = $fittedAt?->getTimestamp();

        for ($i = 0, $count = $set->count(); $i < $count; $i++) {
            $id = $set->id[$i];
            $previous = $stored[$id] ?? null;
            $changed = $previous === null || $previous[0] !== $set->fingerprint[$i];

            if (!$changed && ($mode === self::CHANGED || $previous[1])) {
                continue;
            }

            $live = $mode === self::CHANGED && $previous === null && $fittedTs !== null && $set->tracked[$i] > $fittedTs;
            $batch[] = [$i, $live];

            if (count($batch) >= self::BATCH) {
                $written += $this->flushBatch($set, $replay, $batch, $now);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $written += $this->flushBatch($set, $replay, $batch, $now);
        }

        return $written;
    }

    /**
     * Drops the pair/team rows of times that are no pair/team result to predict any more (now solo, suspicious, without
     * seconds) - bulk, like the writes.
     *
     * @param list<string> $timeIds
     */
    public function forget(array $timeIds): int
    {
        foreach (array_chunk($timeIds, self::BATCH) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));
            $this->connection->executeStatement(
                "DELETE FROM solving_time_prediction WHERE predictor IN ('" . PredictorKind::Group->value . "', '" . PredictorKind::GroupBaseline->value . "') AND time_id IN ({$placeholders})",
                $chunk,
            );
            $this->connection->executeStatement("DELETE FROM group_prediction_input WHERE time_id IN ({$placeholders})", $chunk);
        }

        return count($timeIds);
    }

    /**
     * @param list<array{0: int, 1: bool}> $batch
     */
    private function flushBatch(GroupResultSet $set, GroupReplay $replay, array $batch, DateTimeImmutable $now): int
    {
        $computedAt = $now->format('Y-m-d H:i:s');
        $predictionTuples = [];
        $predictionParams = [];
        $inputTuples = [];
        $inputParams = [];

        foreach ($batch as [$i, $live]) {
            $source = $live ? TimePredictionSource::Live : TimePredictionSource::Reconstructed;

            foreach ([PredictorKind::Group, PredictorKind::GroupBaseline] as $predictor) {
                $values = self::row($set, $replay, $i, $predictor, $source, $computedAt);
                $predictionTuples[] = '(' . implode(', ', array_fill(0, count($values), '?')) . ')';
                array_push($predictionParams, ...$values);
            }

            $inputTuples[] = '(?, ?, ?, ?)';
            array_push($inputParams, $set->id[$i], $set->fingerprint[$i], $live ? 'true' : 'false', $computedAt);
        }

        $update = implode(', ', array_map(
            static fn (string $column): string => sprintf('%s = EXCLUDED.%s', $column, $column),
            array_diff(self::PREDICTION_COLUMNS, ['id', 'time_id', 'predictor', 'model_version']),
        ));

        $this->connection->executeStatement(
            sprintf(
                'INSERT INTO solving_time_prediction (%s) VALUES %s ON CONFLICT (time_id, predictor, model_version) DO UPDATE SET %s',
                implode(', ', self::PREDICTION_COLUMNS),
                implode(', ', $predictionTuples),
                $update,
            ),
            $predictionParams,
        );

        $this->connection->executeStatement(
            'INSERT INTO group_prediction_input (time_id, fingerprint, live, written_at) VALUES ' . implode(', ', $inputTuples) . '
            ON CONFLICT (time_id) DO UPDATE SET fingerprint = EXCLUDED.fingerprint, live = EXCLUDED.live, written_at = EXCLUDED.written_at',
            $inputParams,
        );

        return count($batch);
    }

    /**
     * One row's values in PREDICTION_COLUMNS order.
     *
     * @return list<null|int|float|string>
     */
    private static function row(GroupResultSet $set, GroupReplay $replay, int $i, PredictorKind $predictor, TimePredictionSource $source, string $computedAt): array
    {
        $group = $predictor === PredictorKind::Group;
        $centre = $group ? $replay->centre[$i] : $replay->baselineCentre[$i];
        $window = $group ? $replay->window[$i] : $replay->baselineWindow[$i];
        $kind = $replay->kind[$i];
        $predictable = $centre !== null && $window !== null;
        // A first try's k is the puzzle's index count (the gate's k buckets); a repeat has none (the "repeat" bucket)
        $k = $predictable && $set->first[$i] ? $set->k[$i] : null;
        $logDifficulty = $group ? $replay->logDifficulty[$i] : ($set->score[$i] !== null && $set->score[$i] > 0 ? log($set->score[$i]) : null);
        $difficultySource = $group ? $replay->source[$i] : 'solves';

        return [
            Uuid::uuid7()->toString(),
            $set->id[$i],
            $predictor->value,
            GroupPredictionModel::MODEL_VERSION,
            $source->value,
            $predictable ? 'true' : 'false',
            match (true) {
                !$predictable => null,
                $group && $kind === GroupReplay::KIND_OWN => TimePredictionMethod::Personal->value,
                default => TimePredictionMethod::Statistical->value,
            },
            $predictable ? TimePredictionCalculator::seconds(exp($centre)) : null,
            $predictable ? TimePredictionCalculator::seconds(exp($window[1])) : null,
            $predictable ? TimePredictionCalculator::seconds(exp($window[2])) : null,
            $predictable ? TimePredictionCalculator::seconds(exp($window[0])) : null,
            $predictable ? TimePredictionCalculator::seconds(exp($window[3])) : null,
            $predictable && $logDifficulty !== null ? round(exp($logDifficulty), 6) : null,
            $predictable && $difficultySource !== 'none' ? $difficultySource : null,
            $k,
            null,
            $predictable && $group ? $set->imageLog[$i] : null,
            match (true) {
                !$predictable => null,
                $group => $replay->bucket[$i],
                default => 'baseline|' . GroupCoordination::sizeGroup($set->members[$i]),
            },
            null,
            null,
            null,
            $computedAt,
        ];
    }
}
