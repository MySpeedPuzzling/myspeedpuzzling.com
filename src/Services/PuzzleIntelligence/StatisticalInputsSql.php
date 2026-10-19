<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\PriorContext;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictionModelParameters;
use SpeedPuzzling\Web\Value\StatisticalPredictionInputs;

/**
 * The live tables' side of a statistical prediction, written once for every statement that reads it -
 * GetPlayerPrediction (one puzzle), GetPlayerPredictions (many) and the Puzzle Picker's pre-LIMIT filter, which
 * mirrors TimePredictionCalculator::statistical()'s centre in SQL (centreSql(), pinned equal by
 * GetPuzzlePickerSuggestionsTest). Rows are hydrated by hydrate().
 *
 * Expects `p` = puzzle and the parameter :playerId; the pair's latest pace-history row (drift, spread) comes from
 * `own` and the 500-piece one from `five`, which the statement joins itself (a LATERAL for one puzzle, a DISTINCT ON
 * relation for many: lateralPaceJoins() / ownPaceCte()).
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
     * (Insights plan principle: never leak secret puzzles) - the condition on `p` every prior join carries.
     */
    public const string NOT_SECRET = '(p.hide_until IS NULL OR p.hide_until <= NOW()) AND (p.hide_image_until IS NULL OR p.hide_image_until <= NOW())';

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
     * - a CTE `player_pace` with the player's pace × drift once per piece count of the catalogue (the thin-size rule,
     *   the own baseline or none) - expects the `own_pace` CTE (ownPaceCte()) and :playerId;
     * - joins of `p` to it and to the prior's tables;
     * - the expression over them and `pd`: pace × drift × the score (5+ indices) or × exp(shrunk log difficulty)
     *   (1-4), NULL where the calculator returns null. The same operations in the same order as the calculator -
     *   pinned equal by GetPuzzlePickerSuggestionsTest. BrandPiecesPrior only - a prior added in front of it needs its
     *   term here too.
     *
     * @return array{0: string, 1: string, 2: string, 3: array<string, float|int>} the CTE (with a trailing comma), the joins, the centre, the parameters
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
        $thin = "(rule.alpha IS NOT NULL AND pb500.baseline_seconds > 0 AND (CASE WHEN pb.baseline_type = 'direct' THEN pb.qualifying_solves_count ELSE 0 END) < rule.max_own)";

        $cte = "
            player_pace AS (
                SELECT
                    sizes.pieces_count,
                    (CASE
                        WHEN {$thin} THEN pb500.baseline_seconds * power(sizes.pieces_count::double precision / 500.0::double precision, rule.alpha)
                        WHEN pb.baseline_seconds > 0 THEN pb.baseline_seconds::double precision
                    END) * exp(CASE
                        WHEN {$thin} THEN COALESCE(five.drift, 0)
                        WHEN pb.baseline_seconds > 0 AND pb.baseline_type = 'direct' THEN COALESCE(own.drift, 0)
                        ELSE 0
                    END) AS paced
                FROM (SELECT DISTINCT pieces_count FROM puzzle) sizes
                LEFT JOIN player_baseline pb ON pb.player_id = :playerId AND pb.pieces_count = sizes.pieces_count
                LEFT JOIN player_baseline pb500 ON pb500.player_id = :playerId AND pb500.pieces_count = 500 AND pb500.baseline_type = 'direct'
                LEFT JOIN own_pace own ON own.pieces_count = sizes.pieces_count
                LEFT JOIN own_pace five ON five.pieces_count = 500
                CROSS JOIN LATERAL (SELECT {$alpha} AS alpha, {$max} AS max_own) rule
            ),
        ";

        $notSecret = self::NOT_SECRET;
        $shrunk = TimePredictionCalculator::RATED_INDICES - 1;
        $joins = "
    LEFT JOIN player_pace ppace ON ppace.pieces_count = p.pieces_count
    LEFT JOIN difficulty_prior_group g ON g.manufacturer_id = p.manufacturer_id AND g.pieces_count = p.pieces_count AND pd.sample_size BETWEEN 1 AND {$shrunk} AND {$notSecret}
    LEFT JOIN difficulty_prior_puzzle dpp ON dpp.puzzle_id = p.id AND pd.sample_size BETWEEN 1 AND {$shrunk}
";

        $prior = self::priorSql();
        $rated = TimePredictionCalculator::RATED_INDICES;
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

    /**
     * BrandPiecesPrior::fromSums() in SQL over `g` and `dpp`: NULL without a prior.
     */
    public static function priorSql(): string
    {
        $minimum = BrandPiecesPrior::MINIMUM_GROUP_INDICES;
        $pseudo = BrandPiecesPrior::PSEUDO_COUNT;

        return "(CASE WHEN g.index_count - COALESCE(dpp.index_count, 0) >= {$minimum}
            THEN (g.sum_log_indices - COALESCE(dpp.sum_log_indices, 0)) / CAST(g.index_count - COALESCE(dpp.index_count, 0) + {$pseudo} AS double precision)
        END)";
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
