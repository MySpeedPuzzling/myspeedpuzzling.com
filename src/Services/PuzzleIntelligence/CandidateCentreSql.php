<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PuzzleIntelligence;

use SpeedPuzzling\Web\Services\PuzzleIntelligence\Model\CandidatePredictionCalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\BrandPiecesPrior;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Prior\ImageEstimatePrior;
use SpeedPuzzling\Web\Value\CandidateModelParameters;
use SpeedPuzzling\Web\Value\ImagePriorInput;
use SpeedPuzzling\Web\Value\PaceSource;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\TasteModel;
use SpeedPuzzling\Web\Value\TasteState;

/**
 * The candidate model's first-try centre (CandidatePredictionCalculator::firstTry(), rounded seconds) in SQL for a
 * candidate list - the Puzzle Picker's pre-LIMIT filter while the candidate is the shown model (PREDICTION_SHOWN_MODEL =
 * v7), the twin of StatisticalInputsSql::centreSql() for v3. Pinned equal to the cards' numbers (GetPlayerPredictions,
 * the same calculator) by GetPuzzlePickerSuggestionsShownV7Test.
 *
 * What depends on the player and the piece count only is computed in PHP by the calculator's own code and handed over
 * as one row per catalogue piece count (a VALUES list joined by piece count): the log pace with its drift
 * (CandidatePredictionCalculator::pace() - the size curve, the shown model's pace rules) and the centre offset of each
 * k bucket × prior type (size group × pace source × k bucket × prior type + the player's experience). What depends on
 * the puzzle is computed in SQL with the same IEEE operations in the same order as the calculator, every constant a
 * round-trip literal: the posterior log difficulty from the candidate's evidence and the first prior there is (the
 * estimate of the current picture with its own variance, else the puzzle-weighted brand prior, else the average puzzle),
 * the taste term (the player's coefficients × [1, W x] of the picture's factors, kind and size) and the player's
 * difficulty slope. A puzzle without any candidate index (k = 0) gets NULL - prior-only predictions are not shown on
 * surfaces, like v3's. The taste vector's sums over the 16 picture factors are read stored
 * (candidate_picture_projection - TasteModel::factorSums(), kept by the candidate's jobs) while they are current, and
 * computed from the factors otherwise: 64 multiply-adds and 16 JSON reads per puzzle cost ~4× the rest of the centre on
 * the production copy.
 *
 * Expects `p` = puzzle, `pd` = its puzzle_difficulty row (only read with the switches that need it) and :now.
 */
final class CandidateCentreSql
{
    private const array PRIOR_TYPES = [ImageEstimatePrior::NAME, BrandPiecesPrior::NAME, 'none'];

    /** The k buckets in the order of the offset columns (WindowBucket::kBucket()) */
    private const array K_BUCKETS = [0, 1, 5, 20];

    /**
     * @param array<int, array{0: float, 1: PaceSource}> $paces by piece count: the log pace with its drift and what it rests on (CandidatePredictionCalculator::pace())
     * @param null|int $firstTries the player's qualifying first attempts (the experience offset; StatisticalInputsSql::CANDIDATE_FIRST_TRIES_COLUMN)
     * @param null|TasteState $taste the player's latest taste state (null = none stored: the model's initial state)
     * @return null|array{0: string, 1: string, 2: array<string, string>} the joins, the centre (rounded seconds, NULL = no prediction), the parameters - null without any pace
     */
    public static function centreSql(CandidateModelParameters $parameters, array $paces, null|int $firstTries, null|TasteState $taste): null|array
    {
        if ($paces === []) {
            return null;
        }

        $features = $parameters->features();
        $params = [];
        $rows = [];

        foreach ($paces as $piecesCount => [$logPace, $paceSource]) {
            $group = PiecesGroup::fromPiecesCount($piecesCount);
            $values = [(string) $piecesCount, self::literal($logPace)];

            foreach (self::K_BUCKETS as $kBucket) {
                foreach (self::PRIOR_TYPES as $priorType) {
                    // CandidatePredictionCalculator::firstTry()'s offset, the one double it adds
                    $values[] = self::literal($features->on('centre_offsets')
                        ? $parameters->centreOffset(CandidatePredictionCalculator::offsetKeys($group, $paceSource->value, $kBucket, $priorType)) + $parameters->experienceOffset($firstTries)
                        : 0.0);
                }
            }

            $rows[] = '(' . implode(', ', $values) . ')';
        }

        $offsetColumns = implode(', ', array_map(static fn (int $index): string => 'o' . $index, range(0, count(self::K_BUCKETS) * count(self::PRIOR_TYPES) - 1)));
        $notSecret = StatisticalInputsSql::NOT_SECRET;
        $fingerprint = PuzzleImageFingerprint::sql('p.image');

        $joins = "
    LEFT JOIN (VALUES " . implode(', ', $rows) . ") AS cv7_pace (pieces_count, log_pace, {$offsetColumns}) ON cv7_pace.pieces_count = p.pieces_count
    LEFT JOIN candidate_puzzle_evidence cv7_ev ON cv7_ev.puzzle_id = p.id
    LEFT JOIN candidate_puzzle_prior cv7_brand ON cv7_brand.puzzle_id = p.id
    LEFT JOIN puzzle_difficulty_estimate cv7_img ON cv7_img.puzzle_id = p.id AND cv7_img.image_fingerprint = {$fingerprint} AND {$notSecret}
    LEFT JOIN puzzle_image_analysis cv7_art ON cv7_art.puzzle_id = cv7_img.puzzle_id AND cv7_art.analyzer = 'artwork' AND cv7_art.image_fingerprint = cv7_img.image_fingerprint
    LEFT JOIN candidate_picture_projection cv7_tp ON cv7_tp.puzzle_id = p.id
    CROSS JOIN LATERAL (
        SELECT
            CASE WHEN cv7_ev.index_count > 0 THEN cv7_ev.index_count ELSE 0 END AS k,
            CASE WHEN cv7_ev.index_count > 0 THEN COALESCE(cv7_ev.weight_sum, 0::float8) ELSE 0::float8 END AS weight_sum,
            CASE WHEN cv7_ev.index_count > 0 THEN COALESCE(cv7_ev.weighted_log_sum, 0::float8) ELSE 0::float8 END AS weighted_log_sum,
            (cv7_img.log_difficulty IS NOT NULL AND cv7_img.log_p10 IS NOT NULL AND cv7_img.log_p90 IS NOT NULL AND cv7_img.model IS NOT NULL) AS has_estimate,
            " . self::brandSql($features->on('puzzle_weighted_brand')) . " AS brand_log
    ) cv7_in
    CROSS JOIN LATERAL (
        SELECT " . self::priorSql($parameters) . "
    ) cv7_prior
    CROSS JOIN LATERAL (
        SELECT " . self::posteriorSql($parameters) . " AS log_difficulty
    ) cv7_post
";

        [$tasteSql, $tasteParams] = self::tasteSql($parameters, $taste);
        $params = [...$params, ...$tasteParams];

        $kIndex = 'CASE WHEN cv7_in.k <= 0 THEN 0 WHEN cv7_in.k < 5 THEN 1 WHEN cv7_in.k < 20 THEN 2 ELSE 3 END';
        $offsetCases = [];

        foreach (range(0, count(self::K_BUCKETS) * count(self::PRIOR_TYPES) - 1) as $index) {
            $offsetCases[] = "WHEN {$index} THEN cv7_pace.o{$index}";
        }

        $offset = "(CASE ({$kIndex}) * 3 + cv7_prior.prior_type " . implode(' ', $offsetCases) . ' END)';
        // exp(logPace + logDifficulty + offset + taste + 0.0), left to right like the calculator; never above
        // MAX_SECONDS (exp() of PHP is INF there, of PostgreSQL an error)
        $logCentre = "((((cv7_pace.log_pace + cv7_post.log_difficulty) + {$offset}) + {$tasteSql}) + 0::float8)";
        $centre = "CASE
            WHEN cv7_pace.log_pace IS NULL OR cv7_in.k <= 0 THEN NULL
            ELSE GREATEST(LEAST(round(exp(LEAST({$logCentre}, 700::float8))), " . TimePredictionCalculator::MAX_SECONDS . "::float8)::bigint, 1)::int
        END";

        return [$joins, $centre, $params];
    }

    /**
     * Every catalogue piece count's pace for the player (CandidatePredictionCalculator::pace()) - from the rows of the
     * pace statement (GetPlayerPredictions::candidatePaces()), hydrated like a puzzle's row.
     *
     * @param list<array<string, mixed>> $sizeRows one per piece count: StatisticalInputsSql's player columns at that count
     * @param array<string, mixed> $player the player's candidate columns (StatisticalInputsSql::candidatePlayerColumns())
     * @return array<int, array{0: float, 1: PaceSource}>
     */
    public static function paces(array $sizeRows, array $player, CandidateModelParameters $parameters): array
    {
        $paces = [];

        foreach ($sizeRows as $row) {
            $inputs = StatisticalInputsSql::hydrateCandidatePace(StatisticalInputsSql::withPlayerColumns($row, $player), StatisticalInputsSql::hydrate($row), $parameters);
            $pace = CandidatePredictionCalculator::pace($inputs, $parameters);

            if ($pace !== null && is_numeric($row['pieces_count'] ?? null)) {
                $paces[(int) $row['pieces_count']] = [$pace[0], $pace[3]];
            }
        }

        return $paces;
    }

    /**
     * The brand prior (CandidatePredictionCalculator::prior()): the candidate's puzzle-weighted one (candidate_puzzle_prior),
     * or with the switch off the shown model's index-weighted one (its finished copy on the difficulty row) - none for a
     * secret puzzle.
     */
    private static function brandSql(bool $puzzleWeighted): string
    {
        $notSecret = StatisticalInputsSql::NOT_SECRET;

        return $puzzleWeighted
            ? "CASE WHEN {$notSecret} THEN cv7_brand.brand_prior_log END"
            : "CASE WHEN {$notSecret} THEN pd.prior_log END";
    }

    /**
     * The prior's type (0 image, 1 brand, 2 none - PRIOR_TYPES), log and precision (1 ÷ max(variance, 1e-6) -
     * CandidateEvidence::posterior()), the variance by CandidatePredictionCalculator::priorVarianceOf().
     */
    private static function priorSql(CandidateModelParameters $parameters): string
    {
        $features = $parameters->features();
        $image = $features->on('image_prior') ? 'cv7_in.has_estimate' : 'false';
        $precision = static fn (float $variance): string => self::literal(1.0 / max($variance, 1e-6));

        if ($features->on('prior_types') === false) {
            $pooled = $precision($parameters->priorVariance('pooled'));
            [$imagePrecision, $brandPrecision, $nonePrecision] = [$pooled, $pooled, $pooled];
        } else {
            $imagePrecision = self::imagePrecisionSql($parameters);
            $brandPrecision = $precision($parameters->priorVariance(BrandPiecesPrior::NAME));
            $nonePrecision = $precision($parameters->priorVariance('none'));
        }

        return "
            CASE WHEN {$image} THEN 0 WHEN cv7_in.brand_log IS NOT NULL THEN 1 ELSE 2 END AS prior_type,
            CASE WHEN {$image} THEN cv7_img.log_difficulty WHEN cv7_in.brand_log IS NOT NULL THEN cv7_in.brand_log ELSE 0::float8 END AS prior_log,
            CASE WHEN {$image} THEN {$imagePrecision} WHEN cv7_in.brand_log IS NOT NULL THEN {$brandPrecision} ELSE {$nonePrecision} END AS prior_precision
        ";
    }

    /**
     * 1 ÷ max(CandidateModelParameters::imagePriorVariance(the estimate's variance), 1e-6): the fitted image variance ×
     * (the estimate's variance ÷ the reference)^exponent, its variance from its 80 % interval (ImagePriorInput::variance()).
     */
    private static function imagePrecisionSql(CandidateModelParameters $parameters): string
    {
        $variance = $parameters->priorVariance(ImageEstimatePrior::NAME);
        $plain = self::literal(1.0 / max($variance, 1e-6));

        if ($parameters->imageIntervalExponent === 0.0 || $parameters->imageIntervalReference <= 0.0 || $parameters->features()->on('image_interval') === false) {
            return $plain;
        }

        $estimateVariance = 'power(GREATEST(0::float8, cv7_img.log_p90 - cv7_img.log_p10) / ' . self::literal(2 * ImagePriorInput::Z90) . ', 2::float8)';

        return "CASE WHEN {$estimateVariance} <= 0 THEN {$plain} ELSE 1::float8 / GREATEST("
            . self::literal($variance) . " * power({$estimateVariance} / " . self::literal($parameters->imageIntervalReference) . ', ' . self::literal($parameters->imageIntervalExponent) . '), '
            . self::literal(1e-6) . ') END';
    }

    /**
     * CandidatePredictionCalculator::firstTry()'s log difficulty: (Σ w l + m × precision) ÷ (Σ w + precision); with the
     * switch posterior_all_k off the shown median from 5 indices on.
     */
    private static function posteriorSql(CandidateModelParameters $parameters): string
    {
        $posterior = '(cv7_in.weighted_log_sum + cv7_prior.prior_precision * cv7_prior.prior_log) / (cv7_in.weight_sum + cv7_prior.prior_precision)';

        if ($parameters->features()->on('posterior_all_k')) {
            return $posterior;
        }

        return 'CASE WHEN cv7_in.k >= ' . TimePredictionCalculator::RATED_INDICES . " AND pd.difficulty_score > 0 AND cv7_in.weight_sum > 0 THEN ln(pd.difficulty_score) ELSE {$posterior} END";
    }

    /**
     * The taste term (CandidatePredictionCalculator::taste(), no event): Σ cᵢ vᵢ with v = [1, W x] (TasteState::term(),
     * TasteModel::project(): each row's sum from 0.0 in feature order, terms of a zero feature left out - adding a
     * zero changes no sum), + the player's slope γ (d - mean) (TasteState::slopeTerm()).
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private static function tasteSql(CandidateModelParameters $parameters, null|TasteState $state): array
    {
        $model = $parameters->taste;

        if ($model === null || $parameters->features()->on('taste') === false) {
            return ['0::float8', []];
        }

        $state ??= $model->initialState();
        $factorsValid = "(cv7_img.picture_factors_version = :cv7FactorsVersion AND jsonb_typeof(cv7_img.picture_factors) = 'array' AND jsonb_array_length(cv7_img.picture_factors) = 16)";
        // The stored sums (candidate_picture_projection) while they are of this picture, this push, this basis and this
        // fit's W - TasteModel::factorSums(), the same doubles; else computed here from the factors (a push since the
        // last refresh): the very same operations either way
        $projectionCurrent = 'cv7_tp.image_fingerprint = cv7_img.image_fingerprint AND cv7_tp.estimate_received_at = cv7_img.received_at'
            . ' AND cv7_tp.factors_version = :cv7FactorsVersion AND cv7_tp.factors_version = cv7_img.picture_factors_version'
            . ' AND cv7_tp.taste_fitted_at = (SELECT fit.fitted_at FROM prediction_model_fit fit WHERE fit.model_version = ' . CandidatePredictionCalculator::MODEL_VERSION . ')';
        $box = "cv7_art.result->>'kind' IN ('box_front', 'box_angled')";
        $assembled = "cv7_art.result->>'kind' = 'assembled'";
        $factorsCount = TasteModel::FEATURES - 5;
        $projections = [];
        $canStore = count($model->weights) === TasteModel::RANK;

        foreach ($model->weights as $r => $weights) {
            $factorSum = '0::float8';

            for ($j = 0; $j < $factorsCount; $j++) {
                $factorSum = "({$factorSum} + " . self::literal($weights[$j]) . " * (cv7_img.picture_factors->>{$j})::float8)";
            }

            $stored = $canStore ? "WHEN {$projectionCurrent} THEN cv7_tp.factor_sum" . ($r + 1) . ' ' : '';

            // The kind (box xor assembled) and the size group (at most one of three) - the features after the factors
            $projection = "(CASE {$stored}WHEN {$factorsValid} THEN {$factorSum} ELSE 0::float8 END + CASE WHEN {$box} THEN " . self::literal($weights[$factorsCount]) . " WHEN {$assembled} THEN " . self::literal($weights[$factorsCount + 1]) . ' ELSE 0::float8 END)';
            $projection = "({$projection} + CASE WHEN p.pieces_count <= 300 THEN " . self::literal($weights[$factorsCount + 2])
                . ' WHEN p.pieces_count = 1000 THEN ' . self::literal($weights[$factorsCount + 3])
                . ' WHEN p.pieces_count <> 500 THEN ' . self::literal($weights[$factorsCount + 4]) . ' ELSE 0::float8 END)';
            $projections[] = $projection;
        }

        $term = '0::float8';

        foreach ($state->coefficients as $i => $coefficient) {
            $term = "({$term} + " . self::literal($coefficient) . ' * ' . ($i === 0 ? '1::float8' : $projections[$i - 1]) . ')';
        }

        $slope = $parameters->features()->on('player_slope') ? $state->slopeCoefficients(TasteModel::SLOPE_LAMBDA, TasteModel::SLOPE_MINIMUM) : null;

        if ($slope !== null) {
            $term = "({$term} + " . self::literal($slope[0]) . ' * (cv7_post.log_difficulty - ' . self::literal($slope[1]) . '))';
        }

        return [$term, ['cv7FactorsVersion' => $model->factorsVersion]];
    }

    /**
     * A double as an SQL literal that reads back as the very same double (the shortest round-trip form).
     */
    private static function literal(float $value): string
    {
        return "'" . var_export($value, true) . "'::float8";
    }
}
