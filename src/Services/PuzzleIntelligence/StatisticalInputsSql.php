<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Value\CandidateEvidence;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The live tables' side of a statistical prediction, written once for every statement that reads it -
 * GetPlayerPrediction (one puzzle), GetPlayerPredictions (many) and the Puzzle Picker's pre-LIMIT filter, which
 * mirrors TimePredictionCalculator::statistical()'s centre in SQL (centreSql(), pinned equal by
 * GetPuzzlePickerSuggestionsTest). Rows are hydrated by hydrate().
 *
 * Expects `p` = puzzle and the parameters :playerId and :now (the clock's present as 'Y-m-d H:i:s', NOT_SECRET); the
 * pair's latest pace-history row (drift, spread) comes from `own` and the 500-piece one from `five`, which the
 * statement joins itself (a LATERAL for one puzzle, a DISTINCT ON relation for many: lateralPaceJoins() / ownPaceCte()).
 */
final class StatisticalInputsSql
{
    public const string COLUMNS = '
        p.id AS puzzle_id,
        p.pieces_count,
        p.manufacturer_id,
        pb.baseline_seconds,
        pb.baseline_type,
        pb.qualifying_solves_count,
        pb500.baseline_seconds AS pace500_seconds,
        own.drift AS own_drift,
        own.spread AS own_spread,
        five.drift AS drift500,
        five.spread AS spread500,
        pd.sample_size,
        pd.sum_log_indices,
        pd.difficulty_score,
        dpp.sum_log_indices AS prior_fit_sum_log,
        dpp.index_count AS prior_fit_count,
        g.sum_log_indices AS group_sum_log,
        g.index_count AS group_index_count
    ';

    public const string JOINS = "
        LEFT JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = p.pieces_count
        LEFT JOIN player_baseline pb500 ON pb500.player_id = :playerId AND pb500.pieces_count = 500 AND pb500.baseline_type = 'direct'
        LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = p.id
        LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND " . self::NOT_SECRET . "
        LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id
    ";

    /**
     * A secret puzzle (hide_until / hide_image_until in the future) has no prior and is no training row of one
     * (Insights plan principle: never leak secret puzzles) - the condition on `p` every prior join carries. Expects
     * :now = the clock's present ('Y-m-d H:i:s') - the real present also for a reconstruction as of an earlier solve:
     * a puzzle secret today must not leak into any prediction computed today.
     */
    public const string NOT_SECRET = '(p.hide_until IS NULL OR p.hide_until <= CAST(:now AS timestamp)) AND (p.hide_image_until IS NULL OR p.hide_image_until <= CAST(:now AS timestamp))';

    /**
     * The puzzle's image estimate for the side-by-side `image` / `blend` predictors (ImagePriorInput::fromRow()) -
     * with imageEstimateJoins(); never part of COLUMNS: the statistical predictor does not use it.
     */
    public const string IMAGE_ESTIMATE_COLUMNS = "
        img.log_difficulty AS image_log,
        img.log_p10 AS image_log_p10,
        img.log_p90 AS image_log_p90,
        img.model AS image_model,
        img.model_version AS image_model_version,
        img.log_p25 AS image_log_p25,
        img.log_p75 AS image_log_p75,
        img.pixels_per_piece AS image_pixels_per_piece,
        img.crop_trust AS image_crop_trust,
        art.result->>'kind' AS image_artwork_kind
    ";

    /**
     * The candidate model's (v4, never shown) inputs of the puzzle and the player - only on the live side-by-side
     * statement (GetPlayerPrediction::sideBySide()), never on a shown reader: its evidence (candidate_puzzle_evidence),
     * its brand prior (none for a secret puzzle) and the player's qualifying first attempts so far (one per puzzle -
     * custom_pst_intelligence). With CANDIDATE_JOINS; hydrateCandidate() reads them.
     */
    public const string CANDIDATE_COLUMNS = "
        ce.index_count AS candidate_index_count,
        ce.weight_sum AS candidate_weight_sum,
        ce.weighted_log_sum AS candidate_weighted_log_sum,
        ce.log_sum AS candidate_log_sum,
        ce.log_square_sum AS candidate_log_square_sum,
        ce.noise_sum AS candidate_noise_sum,
        CASE WHEN " . self::NOT_SECRET . " THEN dpp.candidate_prior_log END AS candidate_prior_log,
        (
            SELECT count(DISTINCT own_first.puzzle_id)
            FROM puzzle_solving_time own_first
            WHERE own_first.player_id = :playerId
                AND own_first.puzzling_type = 'solo'
                AND own_first.suspicious = false
                AND own_first.unboxed = false
                AND own_first.seconds_to_solve IS NOT NULL
        ) AS candidate_first_tries
    ";

    public const string CANDIDATE_JOINS = '
        LEFT JOIN candidate_puzzle_evidence ce ON ce.puzzle_id = p.id
    ';

    /**
     * The inputs with the candidate's columns of the row (CANDIDATE_COLUMNS).
     *
     * @param array<string, mixed> $row
     */
    public static function hydrateCandidate(array $row, StatisticalPredictionInputs $inputs): StatisticalPredictionInputs
    {
        $count = self::int($row['candidate_index_count'] ?? null) ?? 0;
        $evidence = $count > 0
            ? new CandidateEvidence(
                $count,
                self::float($row['candidate_weight_sum'] ?? null) ?? 0.0,
                self::float($row['candidate_weighted_log_sum'] ?? null) ?? 0.0,
                self::float($row['candidate_log_sum'] ?? null) ?? 0.0,
                self::float($row['candidate_log_square_sum'] ?? null) ?? 0.0,
                self::float($row['candidate_noise_sum'] ?? null) ?? 0.0,
            )
            : CandidateEvidence::none();

        return $inputs->withCandidate($evidence, self::float($row['candidate_prior_log'] ?? null), self::int($row['candidate_first_tries'] ?? null));
    }

    /**
     * The estimate of the puzzle's current picture (its fingerprint) and the `artwork` kind of that picture - none for
     * a secret puzzle or picture. $outOfFoldOnly for a reconstruction (PuzzleDifficultyEstimate::$outOfFold): the
     * pipeline pushes `outOfFold: false` only for a puzzle that had no label when the model was trained (the full model,
     * which never saw it either - image-pipeline.md §7), so it holds none of the puzzle's solves; the filter is the
     * conservative choice all the same - the flag is the only evidence the app has of what the model saw, and the next
     * derive re-pushes the puzzle out of fold once it is labelled. Expects `p` and :now.
     */
    public static function imageEstimateJoins(bool $outOfFoldOnly): string
    {
        $fingerprint = PuzzleImageFingerprint::sql('p.image');
        $outOfFold = $outOfFoldOnly ? 'AND img.out_of_fold = true' : '';

        return "
            LEFT JOIN puzzle_difficulty_estimate img ON img.puzzle_id = p.id AND img.image_fingerprint = {$fingerprint} {$outOfFold} AND " . self::NOT_SECRET . "
            LEFT JOIN puzzle_image_analysis art ON art.puzzle_id = img.puzzle_id AND art.analyzer = 'artwork' AND art.image_fingerprint = img.image_fingerprint
        ";
    }

    /**
     * One puzzle: the latest row of the pair and of the 500-piece pair, one index step each.
     */
    public static function lateralPaceJoins(): string
    {
        return self::lateralPace('own', 'p.pieces_count') . self::lateralPace('five', '500');
    }

    /**
     * Many puzzles: the player's latest row per piece count, once ("own_pace", joined as `own` and `five`).
     */
    public static function ownPaceCte(): string
    {
        return '
            own_pace AS (
                SELECT DISTINCT ON (h.pieces_count) h.pieces_count, h.drift, h.spread
                FROM player_pace_history h
                WHERE h.player_id = :playerId
                ORDER BY h.pieces_count, h.valid_from DESC, h.valid_from_tracked_at DESC, h.qualifying_count DESC
            )
        ';
    }

    public static function ownPaceCteJoins(): string
    {
        return '
            LEFT JOIN own_pace own ON own.pieces_count = p.pieces_count
            LEFT JOIN own_pace five ON five.pieces_count = 500
        ';
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): StatisticalPredictionInputs
    {
        $piecesCount = self::int($row['pieces_count']) ?? 0;

        return new StatisticalPredictionInputs(
            piecesCount: $piecesCount,
            ownBaselineSeconds: self::int($row['baseline_seconds'] ?? null),
            ownBaselineDirect: ($row['baseline_type'] ?? null) === 'direct',
            ownQualifyingCount: self::int($row['qualifying_solves_count'] ?? null) ?? 0,
            ownDrift: self::float($row['own_drift'] ?? null),
            ownSpread: self::float($row['own_spread'] ?? null),
            pace500Seconds: self::int($row['pace500_seconds'] ?? null),
            drift500: self::float($row['drift500'] ?? null),
            spread500: self::float($row['spread500'] ?? null),
            kIndices: self::int($row['sample_size'] ?? null) ?? 0,
            sumLogIndices: self::float($row['sum_log_indices'] ?? null),
            difficultyScore: self::float($row['difficulty_score'] ?? null),
            prior: new PriorContext(
                puzzleId: is_string($row['puzzle_id'] ?? null) ? $row['puzzle_id'] : '',
                piecesCount: $piecesCount,
                manufacturerId: is_string($row['manufacturer_id'] ?? null) ? $row['manufacturer_id'] : null,
                brandPiecesGroupSumLog: self::float($row['group_sum_log'] ?? null) ?? 0.0,
                brandPiecesGroupCount: self::int($row['group_index_count'] ?? null) ?? 0,
                brandPiecesOwnSumLog: self::float($row['prior_fit_sum_log'] ?? null) ?? 0.0,
                brandPiecesOwnCount: self::int($row['prior_fit_count'] ?? null) ?? 0,
            ),
        );
    }

    /**
     * TimePredictionCalculator::statistical()'s centre (rounded seconds) in SQL for a candidate list (the Puzzle
     * Picker), for a puzzle with at least one difficulty index (prior-only predictions are not shown on surfaces):
     * - CTEs (expect the `own_pace` CTE - ownPaceCte() -, :playerId and a WITH RECURSIVE): `piece_sizes` = the
     *   catalogue's piece counts by a skip scan of the puzzle.pieces_count index (one probe per size, never the table),
     *   `pace_five` = the player's direct 500-piece pace with its drift (one row or none), `player_pace` = pace × drift
     *   once per piece count instead of once per candidate (the 500 pace × (pieces ÷ 500)^alpha × its drift while a
     *   thin-size rule applies - own first attempts below the rule's -, else the own baseline × its drift) and
     *   `pace_by_size` = those as one array indexed by piece count;
     * - the joins: the pace by a subscript of that array per candidate (its subqueries are evaluated once - joining
     *   player_pace was planned as a nested loop over it for every candidate); the stored finished prior comes with
     *   `pd` (puzzle_difficulty.prior_log, the copy of difficulty_prior_puzzle.prior_log);
     * - the expression over them and `pd`: × the score (5+ indices) or × exp(shrunk log difficulty) (1-4), NULL where
     *   the calculator returns null. The same operations in the same order as the calculator - pinned equal by
     *   GetPuzzlePickerSuggestionsTest. The statistical predictor's prior reaches it through prior_log (the daily fit
     *   writes it); the blend's (the image estimate first) would come through blend_prior_log - not read here.
     *
     * @return array{0: string, 1: string, 2: string, 3: array<string, float|int>} the CTEs (with a trailing comma), the joins, the centre, the parameters
     */
    public static function centreSql(PredictionModelParameters $parameters): array
    {
        $params = ['centreTau' => $parameters->tau];
        $alphaCases = '';
        $maxCases = '';

        foreach ([PiecesGroup::UpTo300, PiecesGroup::Thousand, PiecesGroup::From1500, PiecesGroup::Odd] as $index => $group) {
            $rule = $parameters->thinSizeRule($group);

            if ($rule === null) {
                continue;
            }

            $condition = self::groupCondition($group, 'sizes.pieces_count');
            $alphaCases .= " WHEN {$condition} THEN CAST(:thinAlpha{$index} AS double precision)";
            $maxCases .= " WHEN {$condition} THEN CAST(:thinMax{$index} AS int)";
            $params["thinMax{$index}"] = $rule['max_own_solves'];
            $params["thinAlpha{$index}"] = $rule['alpha'];
        }

        $alpha = $alphaCases !== '' ? "CASE{$alphaCases} END" : 'NULL::double precision';
        $max = $maxCases !== '' ? "CASE{$maxCases} END" : 'NULL::int';
        $thin = "(rule.alpha IS NOT NULL AND pace_five.pace IS NOT NULL AND (CASE WHEN pb.baseline_type = 'direct' THEN pb.qualifying_solves_count ELSE 0 END) < rule.max_own)";

        $cte = "
            piece_sizes AS (
                SELECT min(pieces_count) AS pieces_count FROM puzzle
                UNION ALL
                SELECT (SELECT min(next.pieces_count) FROM puzzle next WHERE next.pieces_count > piece_sizes.pieces_count)
                FROM piece_sizes
                WHERE piece_sizes.pieces_count IS NOT NULL
            ),
            pace_five AS (
                SELECT pb.baseline_seconds::double precision AS pace, COALESCE(own.drift, 0) AS drift
                FROM player_baseline pb
                LEFT JOIN own_pace own ON own.pieces_count = 500
                WHERE pb.player_id = :playerId AND pb.pieces_count = 500 AND pb.baseline_type = 'direct' AND pb.baseline_seconds > 0
            ),
            player_pace AS (
                SELECT sizes.pieces_count, paced.value AS paced
                FROM piece_sizes sizes
                LEFT JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = sizes.pieces_count
                LEFT JOIN own_pace own ON own.pieces_count = sizes.pieces_count
                LEFT JOIN pace_five ON true
                CROSS JOIN LATERAL (SELECT {$alpha} AS alpha, {$max} AS max_own) rule
                CROSS JOIN LATERAL (SELECT CASE
                    WHEN {$thin} THEN pace_five.pace * power(sizes.pieces_count::double precision / 500.0::double precision, rule.alpha) * exp(pace_five.drift)
                    WHEN pb.baseline_seconds > 0 THEN pb.baseline_seconds::double precision * exp(CASE WHEN pb.baseline_type = 'direct' THEN COALESCE(own.drift, 0) ELSE 0 END)
                END AS value) paced
                WHERE sizes.pieces_count IS NOT NULL AND paced.value IS NOT NULL
            ),
            pace_by_size AS (
                -- One row: the paces in one array indexed by piece count (from the smallest), read per candidate by a
                -- subscript of the subqueries below (evaluated once) - a join of player_pace was planned as a nested
                -- loop over it for every candidate, a jsonb object costs a search per candidate. 0 = no pace (a pace is
                -- never 0): an array with NULLs costs a walk of its null bitmap per subscript, one without is O(1)
                SELECT array_agg(COALESCE(pace.paced, 0) ORDER BY sizes.pieces_count) AS paces, min(sizes.pieces_count) AS first_size
                FROM generate_series((SELECT min(pieces_count) FROM player_pace), (SELECT max(pieces_count) FROM player_pace)) AS sizes (pieces_count)
                LEFT JOIN player_pace pace ON pace.pieces_count = sizes.pieces_count
            ),
        ";

        $notSecret = self::NOT_SECRET;
        $rated = TimePredictionCalculator::RATED_INDICES;
        $shrunk = $rated - 1;
        // The finished prior the daily fit stored per puzzle (difficulty_prior_puzzle.prior_log - the prior chain's
        // value, computed from the same sums the other readers build it from), read from its copy on the difficulty row
        // `pd` already joined: no lookup of its own. Measured 2026-10-19 on "any unsolved + budget": deriving it from the
        // group and the puzzle's share (two lookups per candidate) 225 ms, one lookup of difficulty_prior_puzzle 140 ms,
        // the copy 95 ms (main 56 ms)
        $joins = "
    CROSS JOIN LATERAL (
        SELECT NULLIF(((SELECT paces FROM pace_by_size))[p.pieces_count - (SELECT first_size FROM pace_by_size) + 1], 0) AS paced
    ) ppace
";

        $prior = "CASE WHEN {$notSecret} THEN pd.prior_log END";
        $tau = 'CAST(:centreTau AS double precision)';
        // One arm per difficulty source, so a missing difficulty stays NULL (GREATEST() would turn NULL into 1)
        $centre = "CASE
            WHEN ppace.paced IS NULL THEN NULL
            WHEN pd.sample_size >= {$rated} AND pd.difficulty_score > 0
                THEN GREATEST(round(ppace.paced * pd.difficulty_score)::int, 1)
            WHEN pd.sample_size BETWEEN 1 AND {$shrunk} AND pd.sum_log_indices IS NOT NULL
                THEN GREATEST(round(ppace.paced * exp((pd.sum_log_indices + {$tau} * COALESCE({$prior}, 0)) / (pd.sample_size + {$tau})))::int, 1)
        END";

        return [$cte, $joins, $centre, $params];
    }

    private static function groupCondition(PiecesGroup $group, string $column): string
    {
        return match ($group) {
            PiecesGroup::UpTo300 => "{$column} <= 300",
            PiecesGroup::FiveHundred => "{$column} = 500",
            PiecesGroup::Thousand => "{$column} = 1000",
            PiecesGroup::From1500 => "{$column} >= 1500",
            PiecesGroup::Odd => "({$column} > 300 AND {$column} < 1500 AND {$column} NOT IN (500, 1000))",
        };
    }

    private static function lateralPace(string $alias, string $piecesCount): string
    {
        return "
            LEFT JOIN LATERAL (
                SELECT h.drift, h.spread
                FROM player_pace_history h
                WHERE h.player_id = :playerId AND h.pieces_count = {$piecesCount}
                ORDER BY h.valid_from DESC, h.valid_from_tracked_at DESC, h.qualifying_count DESC
                LIMIT 1
            ) {$alias} ON true
        ";
    }

    private static function int(mixed $value): null|int
    {
        return is_int($value) || is_string($value) && is_numeric($value) ? (int) $value : null;
    }

    private static function float(mixed $value): null|float
    {
        return is_float($value) || is_int($value) || is_string($value) && is_numeric($value) ? (float) $value : null;
    }
}
