<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Results\InsightsSoloResult;
use SpeedPuzzling\Web\Value\InsightsImageTrait;

/**
 * Every solo result of one player for the Insights page, in one statement (docs/features/insights/computation.md
 * "Core sections" §2): the result, its puzzle's statistics (puzzle_statistics) and difficulty with the derived
 * metrics and shrinkage fields (puzzle_difficulty), the prediction stored for it, whether the puzzle is on the player's
 * shelf (any collection) and the picture's image-trait tags (puzzle_tag). Oldest first.
 *
 * Self-only: the owner's own data, private players included - no other player is read. Left out:
 * - suspicious results: Insights is statistics, and no statistic counts them (docs/features/suspicious-times.md); the
 *   player's own results page still lists them;
 * - results without a time (0 s / none);
 * - secret puzzles (hide_until / hide_image_until in the future): no aggregate for anything secret (README §6), the
 *   same rule as the profile card.
 * `attempt` counts what is left, like the profile card (InsightsProfileRecalculator).
 *
 * Served by custom_pst_player_puzzle_type (player_id first). Heaviest player of the production copy: see the
 * measurements in computation.md.
 */
readonly final class GetInsightsSoloResults
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PlayerNotFound
     * @return list<InsightsSoloResult>
     */
    public function forPlayer(string $playerId): array
    {
        if (Uuid::isValid($playerId) === false) {
            throw new PlayerNotFound();
        }

        $query = <<<SQL
WITH owned AS MATERIALIZED (
    SELECT DISTINCT puzzle_id FROM collection_item WHERE player_id = :playerId
)
SELECT
    pst.id AS time_id,
    pst.puzzle_id,
    pst.seconds_to_solve,
    COALESCE(pst.finished_at, pst.tracked_at) AS solved_at,
    pst.tracked_at,
    pst.finished_at,
    pst.first_attempt,
    pst.unboxed,
    ROW_NUMBER() OVER (
        PARTITION BY pst.puzzle_id
        ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.tracked_at, pst.id
    ) AS attempt,
    (pst.competition_id IS NOT NULL OR pst.competition_series_id IS NOT NULL) AS at_event,
    (owned.puzzle_id IS NOT NULL) AS on_shelf,
    p.pieces_count,
    p.manufacturer_id,
    m.name AS manufacturer_name,
    ps.median_time_solo,
    ps.solved_times_solo_count,
    pd.difficulty_score,
    pd.difficulty_tier,
    pd.confidence,
    pd.sample_size,
    pd.sum_log_indices,
    pd.memorability_score,
    pd.skill_sensitivity_score,
    pd.predictability_score,
    pd.box_dependence_score,
    pd.improvement_ceiling_score,
    pst.predictable,
    pst.prediction_method,
    pst.predicted_seconds,
    pst.predicted_range_low_seconds,
    pst.predicted_range_high_seconds,
    pst.predicted_range80_low_seconds,
    pst.predicted_range80_high_seconds,
    pst.predicted_attempt_number,
    pst.prediction_model_version,
    (
        SELECT json_agg(t.tag_key ORDER BY t.tag_key)
        FROM puzzle_tag t
        WHERE t.puzzle_id = pst.puzzle_id AND t.tag_key IN (:tagKeys)
    ) AS image_tag_keys
FROM puzzle_solving_time pst
INNER JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = pst.puzzle_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
LEFT JOIN owned ON owned.puzzle_id = pst.puzzle_id
WHERE pst.player_id = :playerId
    AND pst.puzzling_type = 'solo'
    AND pst.suspicious = false
    AND pst.seconds_to_solve > 0
    AND (p.hide_until IS NULL OR p.hide_until <= :now)
    AND (p.hide_image_until IS NULL OR p.hide_image_until <= :now)
ORDER BY solved_at, pst.tracked_at, pst.id
SQL;

        $rows = $this->database->executeQuery(
            $query,
            [
                'playerId' => $playerId,
                'now' => $this->clock->now()->format('Y-m-d H:i:s'),
                'tagKeys' => InsightsImageTrait::tagKeys(),
            ],
            ['tagKeys' => ArrayParameterType::STRING],
        )->fetchAllAssociative();

        $results = [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     time_id: string,
             *     puzzle_id: string,
             *     seconds_to_solve: int|string,
             *     solved_at: string,
             *     tracked_at: string,
             *     finished_at: null|string,
             *     first_attempt: bool|int|string,
             *     unboxed: bool|int|string,
             *     attempt: int|string,
             *     at_event: bool|int|string,
             *     on_shelf: bool|int|string,
             *     pieces_count: int|string,
             *     manufacturer_id: null|string,
             *     manufacturer_name: null|string,
             *     median_time_solo: null|int|string,
             *     solved_times_solo_count: null|int|string,
             *     difficulty_score: null|float|string,
             *     difficulty_tier: null|int|string,
             *     confidence: null|string,
             *     sample_size: null|int|string,
             *     sum_log_indices: null|float|string,
             *     memorability_score: null|float|string,
             *     skill_sensitivity_score: null|float|string,
             *     predictability_score: null|float|string,
             *     box_dependence_score: null|float|string,
             *     improvement_ceiling_score: null|float|string,
             *     predictable: null|bool|int|string,
             *     prediction_method: null|string,
             *     predicted_seconds: null|int|string,
             *     predicted_range_low_seconds: null|int|string,
             *     predicted_range_high_seconds: null|int|string,
             *     predicted_range80_low_seconds: null|int|string,
             *     predicted_range80_high_seconds: null|int|string,
             *     predicted_attempt_number: null|int|string,
             *     prediction_model_version: null|int|string,
             *     image_tag_keys: null|string,
             * } $row
             */
            $results[] = InsightsSoloResult::fromDatabaseRow($row);
        }

        return $results;
    }
}
