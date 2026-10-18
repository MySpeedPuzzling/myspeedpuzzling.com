<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * How often the stored live predictions of the current model (solving_time_prediction, statistical predictor) put the
 * actual time inside their windows, per calibration bucket - the input of the daily calibration report. Only results
 * the model learns from count (solo, not suspicious, not unboxed).
 */
readonly final class GetPredictionCalibration
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<array{bucket: string, n: int, hit50: int, hit80: int}>
     */
    public function since(DateTimeImmutable $since): array
    {
        /** @var list<array{bucket: string, n: int|string, hit50: int|string, hit80: int|string}> $rows */
        $rows = $this->database->fetchAllAssociative("
            SELECT
                r.window_bucket AS bucket,
                COUNT(*) AS n,
                COUNT(*) FILTER (WHERE pst.seconds_to_solve BETWEEN r.range50_low AND r.range50_high) AS hit50,
                COUNT(*) FILTER (WHERE pst.seconds_to_solve BETWEEN r.range80_low AND r.range80_high) AS hit80
            FROM solving_time_prediction r
            JOIN puzzle_solving_time pst ON pst.id = r.time_id
            WHERE r.predictor = 'statistical'
                AND r.model_version = :modelVersion
                AND r.source = 'live'
                AND r.predictable = true
                AND r.window_bucket IS NOT NULL
                AND r.computed_at >= :since
                AND pst.suspicious = false
                AND pst.unboxed = false
                AND pst.seconds_to_solve > 0
            GROUP BY r.window_bucket
            ORDER BY r.window_bucket
        ", [
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
            'since' => $since->format('Y-m-d H:i:s'),
        ]);

        return array_map(static fn (array $row): array => [
            'bucket' => $row['bucket'],
            'n' => (int) $row['n'],
            'hit50' => (int) $row['hit50'],
            'hit80' => (int) $row['hit80'],
        ], $rows);
    }
}
