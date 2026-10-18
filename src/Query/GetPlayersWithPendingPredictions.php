<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;

/**
 * Players with at least one solo time with seconds whose prediction was not evaluated yet, or that has no
 * side-by-side row of the current model (solving_time_prediction) - the work list of the prediction backfill;
 * with $recomputeReconstructed also those with a shown prediction reconstructed by an older model.
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
    public function all(bool $recomputeReconstructed = false): array
    {
        $outdated = $recomputeReconstructed
            ? "OR (pst.prediction_source = 'reconstructed' AND pst.prediction_model_version < :modelVersion)"
            : '';

        /** @var list<string> */
        return $this->database->fetchFirstColumn("
            SELECT DISTINCT pst.player_id
            FROM puzzle_solving_time pst
            WHERE pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve IS NOT NULL
                AND (
                    pst.predictable IS NULL
                    OR NOT EXISTS (
                        SELECT 1 FROM solving_time_prediction r
                        WHERE r.time_id = pst.id AND r.predictor = 'statistical' AND r.model_version = :modelVersion
                    )
                    {$outdated}
                )
            ORDER BY pst.player_id
        ", ['modelVersion' => TimePredictionCalculator::MODEL_VERSION]);
    }
}
