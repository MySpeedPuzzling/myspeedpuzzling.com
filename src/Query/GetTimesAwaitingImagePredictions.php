<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\StatisticalInputsSql;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;

/**
 * First tries that still need their reconstructed `image` / `blend` side-by-side rows (Insights plan §5.4, the
 * prediction backfill): a first try - its statistical side row of the current model is a statistical prediction or
 * none was possible (a repeat is always predictable: personal) - on a puzzle that now has an **out-of-fold** estimate
 * of its current picture (never a secret puzzle), and no image row yet, or only a live one written while the puzzle had
 * no estimate (image_model NULL - a puzzle added together with its first time). With $recomputeImage also those whose
 * reconstructed image row is older than the estimate it would rest on now (a new derive pushed it after the row was
 * made) - the one-off before an evaluation, not the cron.
 *
 * Times on puzzles without an out-of-fold estimate are not listed: they wait, and a missing estimate never becomes
 * "not predictable" in a reconstruction.
 */
readonly final class GetTimesAwaitingImagePredictions
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * The condition on a solving time `pst` (expects the parameter :modelVersion).
     */
    public static function condition(bool $recomputeImage = false): string
    {
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $stale = $recomputeImage ? "AND NOT (image_row.source = 'reconstructed' AND image_row.computed_at < img.received_at)" : '';

        return "EXISTS (
            SELECT 1
            FROM puzzle p
            JOIN puzzle_difficulty_estimate img ON img.puzzle_id = p.id AND img.image_fingerprint = {$fingerprint} AND img.out_of_fold = true
            WHERE p.id = pst.puzzle_id
                AND {$notSecret}
                AND EXISTS (
                    SELECT 1 FROM solving_time_prediction statistical_row
                    WHERE statistical_row.time_id = pst.id AND statistical_row.predictor = 'statistical' AND statistical_row.model_version = :modelVersion
                        AND (statistical_row.predictable = false OR statistical_row.method = 'statistical')
                )
                AND NOT EXISTS (
                    SELECT 1 FROM solving_time_prediction image_row
                    WHERE image_row.time_id = pst.id AND image_row.predictor = 'image' AND image_row.model_version = :modelVersion
                        AND (image_row.source = 'reconstructed' OR image_row.image_model IS NOT NULL)
                        {$stale}
                )
        )";
    }

    /**
     * @return list<string>
     */
    public function ofPlayer(string $playerId, bool $recomputeImage = false): array
    {
        $condition = self::condition($recomputeImage);

        /** @var list<string> */
        return $this->database->fetchFirstColumn("
            SELECT pst.id
            FROM puzzle_solving_time pst
            WHERE pst.player_id = :playerId
                AND pst.puzzling_type = 'solo'
                AND pst.seconds_to_solve IS NOT NULL
                AND {$condition}
        ", [
            'playerId' => $playerId,
            'modelVersion' => TimePredictionCalculator::MODEL_VERSION,
        ]);
    }
}
