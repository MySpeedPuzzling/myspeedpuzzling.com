<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * Players with at least one solo time with seconds whose prediction was not evaluated yet, or that has no
 * side-by-side row of the current model (solving_time_prediction; once the model is fitted - $withSideRows) - the
 * work list of the prediction backfill; with $recomputeReconstructed (the time of the current fit) also those with a
 * reconstructed prediction computed no later than it (shown, of any model version, or the current model's side-by-side
 * row) - so a run that stopped continues where it was. Once fitted also the players with a first try still waiting for
 * its image / blend rows (GetTimesAwaitingImagePredictions; with $recomputeImage also the stale ones).
 */
readonly final class GetPlayersWithPendingPredictions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return list<string>
     */
    public function all(null|DateTimeImmutable $recomputeReconstructed = null, bool $withSideRows = true, bool $recomputeImage = false): array
    {
        $params = ['modelVersion' => TimePredictionCalculator::MODEL_VERSION];

        $missingSideRow = $withSideRows
            ? "OR NOT EXISTS (
                SELECT 1 FROM solving_time_prediction r
                WHERE r.time_id = pst.id AND r.predictor = 'statistical' AND r.model_version = :modelVersion
            )
            OR " . GetTimesAwaitingImagePredictions::condition($recomputeImage)
            : '';
        $reconstructed = '';

        if ($recomputeReconstructed !== null) {
            $reconstructed = "OR (pst.prediction_source = 'reconstructed' AND pst.prediction_computed_at <= :fittedAt)
            OR EXISTS (
                SELECT 1 FROM solving_time_prediction r
                WHERE r.time_id = pst.id AND r.predictor = 'statistical' AND r.model_version = :modelVersion
                    AND r.source = 'reconstructed' AND r.computed_at <= :fittedAt
            )";
            $params['fittedAt'] = $recomputeReconstructed->format('Y-m-d H:i:s');
        }

        /** @var list<string> */
        return $this->database->fetchFirstColumn("
            SELECT DISTINCT pst.player_id
            FROM puzzle_solving_time pst
            WHERE pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve IS NOT NULL
                AND (
                    pst.predictable IS NULL
                    {$missingSideRow}
                    {$reconstructed}
                )
            ORDER BY pst.player_id
        ", $params);
    }
}
