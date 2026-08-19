<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Results\PuzzlePickerPick;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\PuzzlePickerCriteria;
use SpeedPuzzling\Web\Value\PuzzlePickerGap;
use SpeedPuzzling\Web\Value\PuzzlePickerOrder;

/**
 * Read model of the puzzle picker ("What should I solve next?").
 *
 * Shape: filter → seeded sample → hydrate. Everything the filters need is
 * bounded by the player's own history (their solves, collection items and
 * lent/borrowed rows), aggregated at runtime in CTEs; the candidate set is
 * ordered by md5(seed || id) so the same seed always yields the same order
 * (offset paging never repeats or skips, links are reproducible) and only the
 * LIMIT rows are joined to manufacturer / statistics / difficulty.
 *
 * Semantics (design doc §6.3): "solved" = any solving time of mine incl. duo /
 * team rows I own and team rows where I am a participant — the same meaning as
 * the "Unsolved puzzles" page; my fastest / first / latest use solo,
 * non-suspicious, timed solves; "when solved" = COALESCE(finished_at, tracked_at).
 * A null player (guest) leaves every personal CTE empty.
 *
 * Insights layer (members, design doc §6.4): only when an insights filter is
 * active are puzzle_difficulty / player_baseline / puzzle_statistics joined
 * *before* the LIMIT. My personal predictions (bulk GetPlayerPredictions, PHP)
 * are handed to SQL as unnest(ids, seconds); the statistical fallback
 * round(baseline × difficulty) is plain arithmetic, so one query still does the
 * filtering, the gap ordering and the sampling.
 */
readonly final class GetPuzzlePickerSuggestions
{
    public function __construct(
        private Connection $database,
        private ClockInterface $clock,
        private GetPlayerPredictions $getPlayerPredictions,
    ) {
    }

    public function pick(PuzzlePickerCriteria $criteria, null|string $playerId, int $limit, int $offset = 0): PuzzlePickerPick
    {
        $params = [
            'now' => $this->clock->now()->format('Y-m-d H:i:s'),
            'playerId' => $playerId,
            'source' => $criteria->source->value,
            'solved' => $criteria->solved->value,
            'includeLentOut' => $criteria->includeLentOut ? 1 : 0,
            'seed' => $criteria->seed ?? '',
            'limit' => $limit,
            'offset' => $offset,
        ];
        $types = [];

        $piecesCondition = '';

        if ($criteria->pieces !== []) {
            $ranges = [];

            foreach ($criteria->pieces as $index => [$min, $max]) {
                $ranges[] = "((:piecesMin{$index}::int IS NULL OR p.pieces_count >= :piecesMin{$index}) AND (:piecesMax{$index}::int IS NULL OR p.pieces_count <= :piecesMax{$index}))";
                $params["piecesMin{$index}"] = $min;
                $params["piecesMax{$index}"] = $max;
            }

            $piecesCondition = 'AND (' . implode(' OR ', $ranges) . ')';
        }

        $brandCondition = '';

        if ($criteria->brandIds !== []) {
            $brandCondition = 'AND p.manufacturer_id IN (:brandIds)';
            $params['brandIds'] = $criteria->brandIds;
            $types['brandIds'] = ArrayParameterType::STRING;
        }

        // --- Insights layer: joined and computed before the LIMIT only when asked for ---

        $needsPredictions = $playerId !== null && $criteria->needsPredictions();
        $needsDifficulty = $needsPredictions || $criteria->difficultyTiers !== [];
        $needsCommunityAverage = $criteria->predictedMaxMinutes !== null && $criteria->usesPersonalPrediction() === false;

        $insightsJoins = '';
        $insightsConditions = '';
        $predictedSelect = 'NULL::int AS predicted_seconds, NULL::int AS gap_seconds,';
        $innerOrder = 'md5(:seed || p.id::text)';
        $outerOrder = 'md5(:seed || picked.puzzle_id::text)';

        if ($needsDifficulty) {
            $insightsJoins .= "\n    LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id";
        }

        if ($criteria->difficultyTiers !== []) {
            $insightsConditions .= "\n        AND pd.confidence <> 'insufficient' AND pd.difficulty_tier IN (:difficultyTiers)";
            $params['difficultyTiers'] = $criteria->difficultyTiers;
            $types['difficultyTiers'] = ArrayParameterType::INTEGER;
        }

        if ($needsCommunityAverage) {
            $insightsJoins .= "\n    LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id";
            $insightsConditions .= "\n        AND ps.average_time_solo <= :predictedMaxSeconds";
            $params['predictedMaxSeconds'] = $criteria->predictedMaxMinutes * 60;
        }

        if ($playerId !== null && $needsPredictions) {
            [$predictionIds, $predictionSeconds] = self::predictionArrays($this->getPlayerPredictions->forAllSolvedPuzzles($playerId));

            $insightsJoins .= <<<SQL

    LEFT JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
    LEFT JOIN unnest(:predictionIds::uuid[], :predictionSeconds::int[]) AS pp(puzzle_id, predicted) ON pp.puzzle_id = p.id
    CROSS JOIN LATERAL (
        SELECT COALESCE(
            pp.predicted,
            CASE WHEN pd.difficulty_score IS NOT NULL AND pd.confidence <> 'insufficient' THEN round(pb.baseline_seconds * pd.difficulty_score)::int END
        ) AS predicted_seconds
    ) pr
    CROSS JOIN LATERAL (
        SELECT CASE WHEN COALESCE(s.solve_count_solo, 0) > 0 THEN s.fastest_seconds - pr.predicted_seconds END AS gap_seconds
    ) gp
SQL;
            $params['predictionIds'] = $predictionIds;
            $params['predictionSeconds'] = $predictionSeconds;
            $predictedSelect = 'pr.predicted_seconds, gp.gap_seconds,';

            if ($criteria->predictedMaxMinutes !== null) {
                $insightsConditions .= "\n        AND pr.predicted_seconds <= :predictedMaxSeconds";
                $params['predictedMaxSeconds'] = $criteria->predictedMaxMinutes * 60;
            }

            if ($criteria->gap === PuzzlePickerGap::Slower) {
                $insightsConditions .= "\n        AND gp.gap_seconds >= :gapMinSeconds";
                $params['gapMinSeconds'] = $criteria->gapMinSeconds();
            } elseif ($criteria->gap === PuzzlePickerGap::Faster) {
                $insightsConditions .= "\n        AND gp.gap_seconds <= -1 * :gapMinSeconds";
                $params['gapMinSeconds'] = $criteria->gapMinSeconds();
            }

            if ($criteria->order === PuzzlePickerOrder::GapSlower) {
                $innerOrder = 'gp.gap_seconds DESC NULLS LAST, ' . $innerOrder;
                $outerOrder = 'picked.gap_seconds DESC NULLS LAST, ' . $outerOrder;
            } elseif ($criteria->order === PuzzlePickerOrder::GapFaster) {
                $innerOrder = 'gp.gap_seconds ASC NULLS LAST, ' . $innerOrder;
                $outerOrder = 'picked.gap_seconds ASC NULLS LAST, ' . $outerOrder;
            }
        }

        $query = <<<SQL
WITH my_solves AS (
    SELECT
        pst.puzzle_id,
        count(*) AS solve_count_any,
        count(*) FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL) AS solve_count_solo,
        min(pst.seconds_to_solve) FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL) AS fastest_seconds,
        (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) ASC, pst.tracked_at ASC)
            FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL))[1] AS first_seconds,
        (array_agg(pst.seconds_to_solve ORDER BY COALESCE(pst.finished_at, pst.tracked_at) DESC, pst.tracked_at DESC)
            FILTER (WHERE pst.puzzling_type = 'solo' AND pst.suspicious = false AND pst.seconds_to_solve IS NOT NULL))[1] AS latest_seconds,
        max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
    FROM puzzle_solving_time pst
    WHERE :playerId::uuid IS NOT NULL
        AND pst.player_id = :playerId
    GROUP BY pst.puzzle_id
),
my_team_solves AS (
    SELECT
        pst.puzzle_id,
        count(*) AS solve_count,
        max(COALESCE(pst.finished_at, pst.tracked_at)) AS last_solved_at
    FROM puzzle_solving_time pst
    WHERE :playerId::uuid IS NOT NULL
        AND pst.team IS NOT NULL
        AND pst.player_id <> :playerId
        AND (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
    GROUP BY pst.puzzle_id
),
my_items AS (
    SELECT ci.puzzle_id, array_agg(ci.collection_id) AS collection_ids
    FROM collection_item ci
    WHERE :playerId::uuid IS NOT NULL
        AND ci.player_id = :playerId
    GROUP BY ci.puzzle_id
),
lent_out AS (
    SELECT DISTINCT lp.puzzle_id
    FROM lent_puzzle lp
    WHERE :playerId::uuid IS NOT NULL
        AND lp.owner_player_id = :playerId
),
borrowed AS (
    SELECT DISTINCT lp.puzzle_id
    FROM lent_puzzle lp
    WHERE :playerId::uuid IS NOT NULL
        AND lp.current_holder_player_id = :playerId
),
picked AS (
    SELECT
        p.id AS puzzle_id,
        COALESCE(s.solve_count_any, 0) + COALESCE(ts.solve_count, 0) AS my_solve_count_any,
        COALESCE(s.solve_count_solo, 0) AS my_solve_count_solo,
        s.fastest_seconds AS my_fastest_seconds,
        s.first_seconds AS my_first_seconds,
        s.latest_seconds AS my_latest_seconds,
        GREATEST(s.last_solved_at, ts.last_solved_at) AS my_last_solved_at,
        (mi.puzzle_id IS NOT NULL) AS in_my_collection,
        (b.puzzle_id IS NOT NULL) AS is_borrowed,
        (lo.puzzle_id IS NOT NULL) AS is_lent_out,
        {$predictedSelect}
        count(*) OVER () AS total_matching
    FROM puzzle p
    LEFT JOIN my_solves s ON s.puzzle_id = p.id
    LEFT JOIN my_team_solves ts ON ts.puzzle_id = p.id
    LEFT JOIN my_items mi ON mi.puzzle_id = p.id
    LEFT JOIN lent_out lo ON lo.puzzle_id = p.id
    LEFT JOIN borrowed b ON b.puzzle_id = p.id{$insightsJoins}
    WHERE p.approved = true
        AND (p.hide_until IS NULL OR p.hide_until < :now::timestamp)
        AND (
            :source = 'any'
            OR (:source = 'mine' AND (mi.puzzle_id IS NOT NULL OR b.puzzle_id IS NOT NULL))
            OR (:source = 'not_mine' AND mi.puzzle_id IS NULL)
        )
        AND (:includeLentOut = 1 OR lo.puzzle_id IS NULL)
        AND (
            :solved = 'any'
            OR (:solved = 'never' AND COALESCE(s.solve_count_any, 0) + COALESCE(ts.solve_count, 0) = 0)
            OR (:solved = 'before' AND COALESCE(s.solve_count_any, 0) + COALESCE(ts.solve_count, 0) > 0)
        )
        {$piecesCondition}
        {$brandCondition}{$insightsConditions}
    ORDER BY {$innerOrder}
    LIMIT :limit OFFSET :offset
)
SELECT
    picked.puzzle_id,
    picked.my_solve_count_any,
    picked.my_solve_count_solo,
    picked.my_fastest_seconds,
    picked.my_first_seconds,
    picked.my_latest_seconds,
    picked.my_last_solved_at,
    picked.in_my_collection,
    picked.is_borrowed,
    picked.is_lent_out,
    picked.predicted_seconds,
    picked.gap_seconds,
    picked.total_matching,
    p.name AS puzzle_name,
    p.alternative_name AS puzzle_alternative_name,
    p.identification_number AS puzzle_identification_number,
    p.ean AS puzzle_ean,
    p.pieces_count,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image END AS puzzle_image,
    CASE WHEN p.hide_image_until IS NOT NULL AND p.hide_image_until > :now::timestamp THEN NULL ELSE p.image_ratio END AS puzzle_image_ratio,
    m.id AS manufacturer_id,
    m.name AS manufacturer_name,
    COALESCE(ps.solved_times_solo_count, 0) AS community_solved_count_solo,
    ps.average_time_solo AS community_average_time_solo,
    pd.difficulty_tier,
    pd.confidence AS difficulty_confidence
FROM picked
JOIN puzzle p ON p.id = picked.puzzle_id
LEFT JOIN manufacturer m ON m.id = p.manufacturer_id
LEFT JOIN puzzle_statistics ps ON ps.puzzle_id = p.id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
ORDER BY {$outerOrder}
SQL;

        $rows = $this->database
            ->executeQuery($query, $params, $types)
            ->fetchAllAssociative();

        $totalMatching = 0;
        $suggestions = [];

        foreach ($rows as $row) {
            /**
             * @var array{
             *     puzzle_id: string,
             *     puzzle_name: string,
             *     puzzle_alternative_name: null|string,
             *     puzzle_identification_number: null|string,
             *     puzzle_ean: null|string,
             *     manufacturer_id: null|string,
             *     manufacturer_name: null|string,
             *     pieces_count: int,
             *     puzzle_image: null|string,
             *     puzzle_image_ratio: null|string|float,
             *     community_solved_count_solo: int|string,
             *     community_average_time_solo: null|int|string,
             *     my_solve_count_any: int|string,
             *     my_solve_count_solo: int|string,
             *     my_fastest_seconds: null|int|string,
             *     my_first_seconds: null|int|string,
             *     my_latest_seconds: null|int|string,
             *     my_last_solved_at: null|string,
             *     in_my_collection: bool,
             *     is_borrowed: bool,
             *     is_lent_out: bool,
             *     difficulty_tier: null|int|string,
             *     difficulty_confidence: null|string,
             *     predicted_seconds: null|int|string,
             *     gap_seconds: null|int|string,
             *     total_matching: int|string,
             * } $row
             */
            $totalMatching = (int) $row['total_matching'];
            unset($row['total_matching']);

            $suggestions[] = PuzzlePickerSuggestion::fromDatabaseRow($row);
        }

        return new PuzzlePickerPick($suggestions, $totalMatching);
    }

    /**
     * Personal predictions as two parallel Postgres array literals for
     * unnest(:ids::uuid[], :seconds::int[]) — empty arrays when there are none.
     *
     * @param array<string, TimePredictionResult> $predictions
     *
     * @return array{string, string}
     */
    private static function predictionArrays(array $predictions): array
    {
        $ids = [];
        $seconds = [];

        foreach ($predictions as $puzzleId => $prediction) {
            $ids[] = $puzzleId;
            $seconds[] = $prediction->predictedSeconds;
        }

        return [
            '{' . implode(',', $ids) . '}',
            '{' . implode(',', $seconds) . '}',
        ];
    }
}
