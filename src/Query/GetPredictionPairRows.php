<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Generator;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionPairRow;
use SpeedPuzzling\Web\Value\PredictorKind;
use SpeedPuzzling\Web\Value\StoredPredictionWindows;
use SpeedPuzzling\Web\Value\TimePredictionSource;

/**
 * The rows a candidate prediction is gated on (EvaluatePredictionCandidate, docs/features/insights/
 * image-estimate-gates.md "Candidate time models"): every stored prediction of the candidate predictor and model version
 * - live or reconstructed, first tries and repeats - of a solve since $solvedSince (on puzzles added since $newerThan
 * when given), with the reference predictor's row of the same time beside it.
 *
 * Like the calibration report and the image evaluation: solo, not suspicious, not unboxed, a real time, never a secret
 * puzzle. One statement, streamed.
 */
readonly final class GetPredictionPairRows
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Generator<PredictionPairRow>
     */
    public function rows(
        PredictorKind $candidate,
        int $candidateVersion,
        PredictorKind $reference,
        int $referenceVersion,
        DateTimeImmutable $solvedSince,
        null|DateTimeImmutable $newerThan = null,
    ): Generator {
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $newer = $newerThan !== null ? 'AND p.added_at >= :newerThan' : '';

        $rows = $this->database->iterateAssociative("
            SELECT
                p.pieces_count,
                c.k_indices,
                c.source,
                pst.seconds_to_solve,
                EXTRACT(EPOCH FROM COALESCE(pst.finished_at, pst.tracked_at)) AS solved_ts,
                c.predicted_seconds AS c_seconds,
                c.range80_low AS c_r80_low,
                c.range50_low AS c_r50_low,
                c.range50_high AS c_r50_high,
                c.range80_high AS c_r80_high,
                CASE WHEN r.predictable THEN r.predicted_seconds END AS r_seconds,
                CASE WHEN r.predictable THEN r.range80_low END AS r_r80_low,
                CASE WHEN r.predictable THEN r.range50_low END AS r_r50_low,
                CASE WHEN r.predictable THEN r.range50_high END AS r_r50_high,
                CASE WHEN r.predictable THEN r.range80_high END AS r_r80_high
            FROM solving_time_prediction c
            JOIN puzzle_solving_time pst ON pst.id = c.time_id
            JOIN puzzle p ON p.id = pst.puzzle_id
            LEFT JOIN solving_time_prediction r ON r.time_id = c.time_id AND r.predictor = :reference AND r.model_version = :referenceVersion
            WHERE c.predictor = :candidate
                AND c.model_version = :candidateVersion
                AND c.predictable = true
                AND c.predicted_seconds IS NOT NULL
                AND c.range50_low IS NOT NULL
                AND c.range50_high IS NOT NULL
                AND c.range80_low IS NOT NULL
                AND c.range80_high IS NOT NULL
                AND c.source IN ('live', 'reconstructed')
                AND pst.puzzling_type = 'solo'
                AND pst.suspicious = false
                AND pst.unboxed = false
                AND pst.seconds_to_solve > 0
                AND COALESCE(pst.finished_at, pst.tracked_at) >= :solvedSince
                {$newer}
                AND {$notSecret}
        ", [
            'candidate' => $candidate->value,
            'candidateVersion' => $candidateVersion,
            'reference' => $reference->value,
            'referenceVersion' => $referenceVersion,
            'solvedSince' => $solvedSince->format('Y-m-d H:i:s'),
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
        ] + ($newerThan !== null ? ['newerThan' => $newerThan->format('Y-m-d H:i:s')] : []));

        foreach ($rows as $row) {
            /** @var array{pieces_count: int|string, k_indices: null|int|string, source: string, seconds_to_solve: int|string, solved_ts: float|string, c_seconds: int|string, c_r80_low: int|string, c_r50_low: int|string, c_r50_high: int|string, c_r80_high: int|string, r_seconds: null|int|string, r_r80_low: null|int|string, r_r50_low: null|int|string, r_r50_high: null|int|string, r_r80_high: null|int|string} $row */
            yield new PredictionPairRow(
                sizeGroup: PiecesGroup::fromPiecesCount((int) $row['pieces_count']),
                kIndices: $row['k_indices'] !== null ? (int) $row['k_indices'] : null,
                actualSeconds: (int) $row['seconds_to_solve'],
                solvedAt: (float) $row['solved_ts'],
                live: $row['source'] === TimePredictionSource::Live->value,
                candidate: new StoredPredictionWindows((int) $row['c_seconds'], (int) $row['c_r80_low'], (int) $row['c_r50_low'], (int) $row['c_r50_high'], (int) $row['c_r80_high']),
                reference: StoredPredictionWindows::tryOf(self::intOrNull($row['r_seconds']), self::intOrNull($row['r_r80_low']), self::intOrNull($row['r_r50_low']), self::intOrNull($row['r_r50_high']), self::intOrNull($row['r_r80_high'])),
            );
        }
    }

    private static function intOrNull(null|int|string $value): null|int
    {
        return $value !== null ? (int) $value : null;
    }
}
