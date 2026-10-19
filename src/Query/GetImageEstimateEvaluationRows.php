<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Generator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\ImageEstimateEvaluationRow;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;

/**
 * The rows the evaluation of the picture-based estimates measures (EvaluateImageEstimates, Insights plan §5.4): every
 * stored `image` prediction of the current model that rested on an estimate (image_model set - a live row written
 * without one is no image prediction) - live, or reconstructed, which is always out of fold (PredictionReconstructor) -
 * on a first try at k = 0..$maxK indices, of a puzzle added on or after $newerThan, with the blend and statistical rows
 * of the same time beside it and the puzzle's measured difficulty now.
 *
 * Like the calibration report: solo, not suspicious, not unboxed, a real time. Never a secret puzzle (hide_until /
 * hide_image_until ahead), whatever rows it has from before it was hidden. One statement, streamed.
 */
readonly final class GetImageEstimateEvaluationRows
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return Generator<ImageEstimateEvaluationRow>
     */
    public function rows(DateTimeImmutable $newerThan, int $maxK): Generator
    {
        $notSecret = StatisticalInputsSql::NOT_SECRET;

        $rows = $this->database->iterateAssociative("
            SELECT
                pst.puzzle_id,
                p.pieces_count,
                i.image_kind,
                i.image_model,
                i.image_model_version,
                i.k_indices,
                pst.seconds_to_solve,
                i.prior_log AS image_log,
                EXTRACT(EPOCH FROM i.computed_at) AS computed_ts,
                i.predicted_seconds AS image_seconds,
                i.range50_low AS image_r50_low,
                i.range50_high AS image_r50_high,
                i.range80_low AS image_r80_low,
                i.range80_high AS image_r80_high,
                CASE WHEN b.predictable THEN b.predicted_seconds END AS blend_seconds,
                CASE WHEN b.predictable THEN b.range50_low END AS blend_r50_low,
                CASE WHEN b.predictable THEN b.range50_high END AS blend_r50_high,
                CASE WHEN b.predictable THEN b.range80_low END AS blend_r80_low,
                CASE WHEN b.predictable THEN b.range80_high END AS blend_r80_high,
                CASE WHEN s.predictable THEN s.predicted_seconds END AS statistical_seconds,
                COALESCE(pd.sample_size, 0) AS measured_indices,
                pd.difficulty_score AS measured_score
            FROM solving_time_prediction i
            JOIN puzzle_solving_time pst ON pst.id = i.time_id
            JOIN puzzle p ON p.id = pst.puzzle_id
            LEFT JOIN solving_time_prediction b ON b.time_id = i.time_id AND b.predictor = 'blend' AND b.model_version = i.model_version
            LEFT JOIN solving_time_prediction s ON s.time_id = i.time_id AND s.predictor = 'statistical' AND s.model_version = i.model_version
            LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
            WHERE i.predictor = 'image'
                AND i.model_version = :modelVersion
                AND i.predictable = true
                AND i.image_model IS NOT NULL
                AND i.source IN ('live', 'reconstructed')
                AND i.k_indices BETWEEN 0 AND :maxK
                AND i.prior_log IS NOT NULL
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.unboxed = false
                AND pst.seconds_to_solve > 0
                AND p.added_at >= :newerThan
                AND {$notSecret}
        ", [
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
            'maxK' => $maxK,
            'newerThan' => $newerThan->format('Y-m-d H:i:s'),
        ]);

        foreach ($rows as $row) {
            /** @var array{puzzle_id: string, pieces_count: int|string, image_kind: null|string, image_model: string, image_model_version: null|string, k_indices: int|string, seconds_to_solve: int|string, image_log: float|string, computed_ts: float|string, image_seconds: int|string, image_r50_low: int|string, image_r50_high: int|string, image_r80_low: int|string, image_r80_high: int|string, blend_seconds: null|int|string, blend_r50_low: null|int|string, blend_r50_high: null|int|string, blend_r80_low: null|int|string, blend_r80_high: null|int|string, statistical_seconds: null|int|string, measured_indices: int|string, measured_score: null|float|string} $row */
            yield new ImageEstimateEvaluationRow(
                puzzleId: $row['puzzle_id'],
                sizeGroup: PiecesGroup::fromPiecesCount((int) $row['pieces_count']),
                imageKind: ImageKind::tryFrom((string) $row['image_kind']) ?? ImageKind::Unknown,
                kIndices: (int) $row['k_indices'],
                actualSeconds: (int) $row['seconds_to_solve'],
                imageLogDifficulty: (float) $row['image_log'],
                computedAt: (float) $row['computed_ts'],
                imageSeconds: (int) $row['image_seconds'],
                imageRange50Low: (int) $row['image_r50_low'],
                imageRange50High: (int) $row['image_r50_high'],
                imageRange80Low: (int) $row['image_r80_low'],
                imageRange80High: (int) $row['image_r80_high'],
                blendSeconds: self::intOrNull($row['blend_seconds']),
                blendRange50Low: self::intOrNull($row['blend_r50_low']),
                blendRange50High: self::intOrNull($row['blend_r50_high']),
                blendRange80Low: self::intOrNull($row['blend_r80_low']),
                blendRange80High: self::intOrNull($row['blend_r80_high']),
                statisticalSeconds: self::intOrNull($row['statistical_seconds']),
                measuredIndices: (int) $row['measured_indices'],
                measuredScore: $row['measured_score'] !== null ? (float) $row['measured_score'] : null,
                estimateModel: trim($row['image_model'] . ' ' . ($row['image_model_version'] ?? '')),
            );
        }
    }

    private static function intOrNull(null|int|string $value): null|int
    {
        return $value !== null ? (int) $value : null;
    }
}
