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
     * TimePredictionCalculator::statistical()'s centre (rounded seconds) in SQL over the COLUMNS' relations, for a
     * puzzle with at least one difficulty index (prior-only predictions are not shown on surfaces). NULL when the
     * calculator returns null. BrandPiecesPrior only - a prior added in front of it needs its term here too.
     *
     * @return array{0: string, 1: array<string, float|int>}
     */
    public static function centreSql(PredictionModelParameters $parameters): array
    {
        $params = ['centreTau' => $parameters->tau];
        $thinConditions = [];

        foreach ([PiecesGroup::UpTo300, PiecesGroup::Thousand, PiecesGroup::From1500, PiecesGroup::Odd] as $index => $group) {
            $rule = $parameters->thinSizeRule($group);

            if ($rule === null) {
                continue;
            }

            $thinConditions[$index] = sprintf(
                "%s AND pb500.baseline_seconds > 0 AND (CASE WHEN pb.baseline_type = 'direct' THEN pb.qualifying_solves_count ELSE 0 END) < CAST(:thinMax%d AS int)",
                self::groupCondition($group),
                $index,
            );
            $params["thinMax{$index}"] = $rule['max_own_solves'];
            $params["thinAlpha{$index}"] = $rule['alpha'];
        }

        $baseCases = '';
        $driftCases = '';

        foreach ($thinConditions as $index => $condition) {
            $baseCases .= " WHEN {$condition} THEN pb500.baseline_seconds * power(p.pieces_count::double precision / 500.0::double precision, CAST(:thinAlpha{$index} AS double precision))";
            $driftCases .= " WHEN {$condition} THEN COALESCE(five.drift, 0)";
        }

        $base = "CASE{$baseCases} WHEN pb.baseline_seconds > 0 THEN pb.baseline_seconds::double precision END";
        $drift = "CASE{$driftCases} WHEN pb.baseline_seconds > 0 AND pb.baseline_type = 'direct' THEN COALESCE(own.drift, 0) ELSE 0 END";

        $difficulty = 'CASE
            WHEN pd.sample_size >= ' . TimePredictionCalculator::RATED_INDICES . ' AND pd.difficulty_score > 0 THEN ln(pd.difficulty_score)
            WHEN pd.sample_size BETWEEN 1 AND ' . (TimePredictionCalculator::RATED_INDICES - 1) . ' AND pd.sum_log_indices IS NOT NULL
                THEN (pd.sum_log_indices + CAST(:centreTau AS double precision) * COALESCE(' . self::priorSql() . ', 0)) / (pd.sample_size + CAST(:centreTau AS double precision))
        END';

        // GREATEST() skips NULLs - no pace or no difficulty must stay NULL
        return ["CASE WHEN ({$base}) IS NOT NULL AND ({$difficulty}) IS NOT NULL THEN GREATEST(round(({$base}) * exp(({$difficulty}) + ({$drift})))::int, 1) END", $params];
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

    private static function groupCondition(PiecesGroup $group): string
    {
        return match ($group) {
            PiecesGroup::UpTo300 => 'p.pieces_count <= 300',
            PiecesGroup::FiveHundred => 'p.pieces_count = 500',
            PiecesGroup::Thousand => 'p.pieces_count = 1000',
            PiecesGroup::From1500 => 'p.pieces_count >= 1500',
            PiecesGroup::Odd => '(p.pieces_count > 300 AND p.pieces_count < 1500 AND p.pieces_count NOT IN (500, 1000))',
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
