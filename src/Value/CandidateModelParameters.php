<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The fitted part of the candidate prediction model (v4 - docs/features/puzzle-intelligence/README.md, "Step 8",
 * "The candidate model (v4)"): computed side by side with the shown model, never shown. Fitted once a day with the
 * shown model (CandidateModelFitter) and stored as the prediction_model_fit row of CANDIDATE_MODEL_VERSION.
 *
 * - indexNoise: the variance of one solver's difficulty index around the puzzle's difficulty,
 *   s² = spread2 × spread² + invQ ÷ q + constant + one term per flag (AttemptFlags, a pace not the solver's own direct one);
 *   every index weighs 1 ÷ s² (× 0 when its weight is switched off by the flags' feature);
 * - priorVariances: the variance of each prior type around a puzzle's difficulty (image estimate, brand × pieces,
 *   none = the average puzzle) - the posterior (Σ w l + m ÷ v) ÷ (Σ w + 1 ÷ v), one formula for every k;
 * - brandPseudoPuzzles: the puzzle-weighted brand × pieces prior's pseudo-count of puzzles (toward the average);
 * - centreOffsets: a log offset per size group × pace source × k bucket (× prior type at k = 0) - biases fixed in the
 *   centre instead of hidden in asymmetric quantiles;
 * - first-try window: sigma² = spreadWeight × spread² + posteriorWeight × posterior variance + sizeTerm(group)
 *   + paceTerm(pace source) + dispersionWeight × the puzzle's dispersion, × a context scale (competition, unboxed);
 *   quantiles per CandidateWindowBucket key;
 * - unboxedOffset: the log offset of an unboxed solve;
 * - repeat window: sigma² = spreadWeight × spread² + constant + gapTerm(gap bucket), centre × exp(gapOffset), quantiles
 *   per repeat key with the gap bucket.
 */
readonly final class CandidateModelParameters
{
    public const array DEFAULT_PRIOR_VARIANCES = [
        'image_estimate' => 0.010,
        'brand_pieces' => 0.030,
        'none' => 0.040,
    ];

    private const array RESEARCH_QUANTILES = [-1.2816, -0.6745, 0.6745, 1.2816];

    /** The switches of $features, built once (read per index by CandidateEngine) */
    public CandidateFeatures $switches;

    /** The shown model's parameters with the candidate's thin-size rules - what TimePredictionCalculator::baselineFor() reads the pace with */
    public PredictionModelParameters $paceParameters;

    /**
     * @param array<string, float> $indexNoise spread2, inv_q, constant, flag terms (unflagged, after_group, commented, indirect)
     * @param array<string, float> $priorVariances keyed by prior type (image_estimate, brand_pieces, none)
     * @param array<string, float> $centreOffsets keyed by CandidateWindowBucket::offsetKeys()
     * @param array<string, float> $sizeTerms keyed by PiecesGroup value
     * @param array<string, float> $paceTerms keyed by PaceSource value
     * @param array<string, float> $contextScales competition, unboxed
     * @param list<float> $consistencyEdges
     * @param array<string, array{0: float, 1: float, 2: float, 3: float}> $quantiles keyed by candidate bucket key (first tries "v|…", repeats "w|…")
     * @param array<string, float> $repeatGapTerms keyed by gap bucket
     * @param array<string, float> $repeatGapOffsets keyed by gap bucket
     * @param list<float> $repeatConsistencyEdges
     * @param array<string, bool> $features which parts the fit used (CandidateFeatures)
     * @param array<string, int|float|string> $summary
     * @param array<string, array{alpha: float, max_own_solves: int}> $thinSizes keyed by PiecesGroup value
     */
    public function __construct(
        public array $indexNoise,
        public float $indexClip,
        public array $priorVariances,
        public float $brandPseudoPuzzles,
        public array $centreOffsets,
        public float $spreadWeight,
        public float $posteriorWeight,
        public array $sizeTerms,
        public array $paceTerms,
        public float $dispersionWeight,
        public float $dispersionShrink,
        public array $contextScales,
        public float $unboxedOffset,
        public array $consistencyEdges,
        public array $quantiles,
        public float $repeatSpreadWeight,
        public float $repeatConstant,
        public array $repeatGapTerms,
        public array $repeatGapOffsets,
        public array $repeatConsistencyEdges,
        public array $features = [],
        public null|DateTimeImmutable $fittedAt = null,
        public array $summary = [],
        // The shown model's thin-size rules the candidate's pace shares (TimePredictionCalculator::baselineFor())
        public array $thinSizes = [],
    ) {
        $this->switches = CandidateFeatures::fromArray($features);
        $this->paceParameters = PredictionModelParameters::defaults()->with(thinSizes: $thinSizes);
    }

    /**
     * A copy with the given parts replaced (the fit builds the model part by part).
     *
     * @param null|array<string, float> $indexNoise
     * @param null|array<string, float> $priorVariances
     * @param null|array<string, float> $centreOffsets
     * @param null|array<string, float> $sizeTerms
     * @param null|array<string, float> $paceTerms
     * @param null|array<string, float> $contextScales
     * @param null|list<float> $consistencyEdges
     * @param null|array<string, array{0: float, 1: float, 2: float, 3: float}> $quantiles
     * @param null|array<string, float> $repeatGapTerms
     * @param null|array<string, float> $repeatGapOffsets
     * @param null|list<float> $repeatConsistencyEdges
     * @param null|array<string, bool> $features
     * @param null|array<string, int|float|string> $summary
     * @param null|array<string, array{alpha: float, max_own_solves: int}> $thinSizes
     */
    public function with(
        null|array $indexNoise = null,
        null|float $indexClip = null,
        null|array $priorVariances = null,
        null|float $brandPseudoPuzzles = null,
        null|array $centreOffsets = null,
        null|float $spreadWeight = null,
        null|float $posteriorWeight = null,
        null|array $sizeTerms = null,
        null|array $paceTerms = null,
        null|float $dispersionWeight = null,
        null|float $dispersionShrink = null,
        null|array $contextScales = null,
        null|float $unboxedOffset = null,
        null|array $consistencyEdges = null,
        null|array $quantiles = null,
        null|float $repeatSpreadWeight = null,
        null|float $repeatConstant = null,
        null|array $repeatGapTerms = null,
        null|array $repeatGapOffsets = null,
        null|array $repeatConsistencyEdges = null,
        null|array $features = null,
        null|DateTimeImmutable $fittedAt = null,
        null|array $summary = null,
        null|array $thinSizes = null,
    ): self {
        return new self(
            indexNoise: $indexNoise ?? $this->indexNoise,
            indexClip: $indexClip ?? $this->indexClip,
            priorVariances: $priorVariances ?? $this->priorVariances,
            brandPseudoPuzzles: $brandPseudoPuzzles ?? $this->brandPseudoPuzzles,
            centreOffsets: $centreOffsets ?? $this->centreOffsets,
            spreadWeight: $spreadWeight ?? $this->spreadWeight,
            posteriorWeight: $posteriorWeight ?? $this->posteriorWeight,
            sizeTerms: $sizeTerms ?? $this->sizeTerms,
            paceTerms: $paceTerms ?? $this->paceTerms,
            dispersionWeight: $dispersionWeight ?? $this->dispersionWeight,
            dispersionShrink: $dispersionShrink ?? $this->dispersionShrink,
            contextScales: $contextScales ?? $this->contextScales,
            unboxedOffset: $unboxedOffset ?? $this->unboxedOffset,
            consistencyEdges: $consistencyEdges ?? $this->consistencyEdges,
            quantiles: $quantiles ?? $this->quantiles,
            repeatSpreadWeight: $repeatSpreadWeight ?? $this->repeatSpreadWeight,
            repeatConstant: $repeatConstant ?? $this->repeatConstant,
            repeatGapTerms: $repeatGapTerms ?? $this->repeatGapTerms,
            repeatGapOffsets: $repeatGapOffsets ?? $this->repeatGapOffsets,
            repeatConsistencyEdges: $repeatConsistencyEdges ?? $this->repeatConsistencyEdges,
            features: $features ?? $this->features,
            fittedAt: $fittedAt ?? $this->fittedAt,
            summary: $summary ?? $this->summary,
            thinSizes: $thinSizes ?? $this->thinSizes,
        );
    }

    /**
     * Before the first fit: the review's numbers (time-model REPORT §3, data-mining F6) - only ever used by tests and
     * a candidate run before its first fit; the backfill writes no candidate rows without a fit.
     */
    public static function defaults(): self
    {
        $shown = PredictionModelParameters::defaults();

        return new self(
            indexNoise: ['spread2' => 0.374, 'inv_q' => 0.101, 'constant' => 0.002, 'unflagged' => 0.0, 'after_group' => 0.0, 'commented' => 0.0, 'indirect' => 0.02],
            indexClip: 1.0,
            priorVariances: self::DEFAULT_PRIOR_VARIANCES,
            brandPseudoPuzzles: 3.0,
            centreOffsets: [],
            spreadWeight: $shown->firstTryPlayerSpreadWeight,
            posteriorWeight: 1.0,
            sizeTerms: $shown->firstTrySizeTerms,
            paceTerms: [],
            dispersionWeight: 0.0,
            dispersionShrink: 10.0,
            contextScales: [],
            unboxedOffset: 0.0,
            consistencyEdges: $shown->firstTryConsistencyEdges,
            quantiles: [],
            repeatSpreadWeight: $shown->repeatSpreadWeight,
            repeatConstant: $shown->repeatConstant,
            repeatGapTerms: [],
            repeatGapOffsets: [],
            repeatConsistencyEdges: $shown->repeatConsistencyEdges,
        );
    }

    /**
     * The variance of one index (log units) of a solver with this spread, these qualifying first attempts (0 = the
     * pace was not their own direct one at the size) and these flags (AttemptFlags).
     */
    public function indexVariance(float $spread, int $qualifying, int $flags, bool $indirect): float
    {
        $noise = $this->indexNoise;
        $variance = ($noise['spread2'] ?? 0.0) * $spread ** 2
            + ($qualifying > 0 ? ($noise['inv_q'] ?? 0.0) / $qualifying : 0.0)
            + ($noise['constant'] ?? 0.0)
            + ($indirect ? ($noise['indirect'] ?? 0.0) : 0.0);

        foreach (CandidateFeatures::FLAG_TERMS as $term => $flag) {
            if (($flags & $flag) !== 0) {
                $variance += $noise[$term] ?? 0.0;
            }
        }

        return max($variance, 0.0005);
    }

    public function priorVariance(string $priorType): float
    {
        return $this->priorVariances[$priorType] ?? self::DEFAULT_PRIOR_VARIANCES[$priorType] ?? self::DEFAULT_PRIOR_VARIANCES['none'];
    }

    /**
     * The experience bucket of a player's qualifying first attempts before a prediction (null = unknown, no offset)
     */
    public static function experienceBucket(null|int $firstTries): null|string
    {
        return match (true) {
            $firstTries === null => null,
            $firstTries < 10 => 'lt10',
            $firstTries < 30 => 'lt30',
            $firstTries < 100 => 'lt100',
            default => 'from100',
        };
    }

    public function experienceOffset(null|int $firstTries): float
    {
        $bucket = self::experienceBucket($firstTries);

        return $bucket !== null ? ($this->centreOffsets['experience|' . $bucket] ?? 0.0) : 0.0;
    }

    /**
     * The first match of the keys, from the most specific (0.0 = none).
     *
     * @param list<string> $keys
     */
    public function centreOffset(array $keys): float
    {
        foreach ($keys as $key) {
            if (isset($this->centreOffsets[$key])) {
                return $this->centreOffsets[$key];
            }
        }

        return 0.0;
    }

    public function firstTrySigma(float $spread, float $posteriorVariance, PiecesGroup $group, PaceSource $paceSource, float $dispersion, float $contextScale, null|int $firstTries = null): float
    {
        $experience = self::experienceBucket($firstTries);
        $variance = $this->spreadWeight * $spread ** 2
            + $this->posteriorWeight * $posteriorVariance
            + ($this->sizeTerms[$group->value] ?? 0.0)
            + ($this->paceTerms[$paceSource->value] ?? 0.0)
            + ($experience !== null ? ($this->paceTerms['experience|' . $experience] ?? 0.0) : 0.0)
            + $this->dispersionWeight * $dispersion;

        return max(sqrt(max($variance * $contextScale, 0.0)), PredictionModelParameters::MINIMUM_SIGMA);
    }

    public function repeatSigma(float $spread, string $gapBucket): float
    {
        $variance = $this->repeatSpreadWeight * $spread ** 2 + $this->repeatConstant + ($this->repeatGapTerms[$gapBucket] ?? 0.0);

        return max(sqrt(max($variance, 0.0)), PredictionModelParameters::MINIMUM_SIGMA);
    }

    /**
     * @param list<string> $keys from the most specific
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function quantilesFor(array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($this->quantiles[$key])) {
                return $this->quantiles[$key];
            }
        }

        return self::RESEARCH_QUANTILES;
    }

    public function contextScale(bool $competition, bool $unboxed): float
    {
        return ($competition ? ($this->contextScales['competition'] ?? 1.0) : 1.0)
            * ($unboxed ? ($this->contextScales['unboxed'] ?? 1.0) : 1.0);
    }

    public function features(): CandidateFeatures
    {
        return $this->switches;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'index_noise' => $this->indexNoise,
            'index_clip' => $this->indexClip,
            'prior_variances' => $this->priorVariances,
            'brand_pseudo_puzzles' => $this->brandPseudoPuzzles,
            'centre_offsets' => $this->centreOffsets,
            'first_try' => [
                'spread_weight' => $this->spreadWeight,
                'posterior_weight' => $this->posteriorWeight,
                'size_terms' => $this->sizeTerms,
                'pace_terms' => $this->paceTerms,
                'dispersion_weight' => $this->dispersionWeight,
                'dispersion_shrink' => $this->dispersionShrink,
                'context_scales' => $this->contextScales,
                'unboxed_offset' => $this->unboxedOffset,
                'consistency_edges' => $this->consistencyEdges,
            ],
            'repeat' => [
                'spread_weight' => $this->repeatSpreadWeight,
                'constant' => $this->repeatConstant,
                'gap_terms' => $this->repeatGapTerms,
                'gap_offsets' => $this->repeatGapOffsets,
                'consistency_edges' => $this->repeatConsistencyEdges,
            ],
            'quantiles' => $this->quantiles,
            'features' => $this->features,
            'thin_sizes' => $this->thinSizes,
            'fitted_at' => $this->fittedAt?->format(DATE_ATOM),
            'summary' => $this->summary,
        ];
    }

    /**
     * Whatever the stored row lacks comes from defaults().
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();
        $firstTry = is_array($data['first_try'] ?? null) ? $data['first_try'] : [];
        $repeat = is_array($data['repeat'] ?? null) ? $data['repeat'] : [];

        $quantiles = [];

        foreach (is_array($data['quantiles'] ?? null) ? $data['quantiles'] : [] as $key => $values) {
            if (is_string($key) && is_array($values) && count($values) === 4) {
                $values = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_values($values));
                $quantiles[$key] = [$values[0], $values[1], $values[2], $values[3]];
            }
        }

        $features = [];

        foreach (is_array($data['features'] ?? null) ? $data['features'] : [] as $key => $value) {
            if (is_string($key) && is_bool($value)) {
                $features[$key] = $value;
            }
        }

        $summary = [];

        foreach (is_array($data['summary'] ?? null) ? $data['summary'] : [] as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                $summary[$key] = $value;
            }
        }

        $thinSizes = [];

        foreach (is_array($data['thin_sizes'] ?? null) ? $data['thin_sizes'] : [] as $group => $rule) {
            if (is_string($group) && is_array($rule) && is_numeric($rule['alpha'] ?? null) && is_numeric($rule['max_own_solves'] ?? null)) {
                $thinSizes[$group] = ['alpha' => (float) $rule['alpha'], 'max_own_solves' => (int) $rule['max_own_solves']];
            }
        }

        return new self(
            indexNoise: self::floats($data['index_noise'] ?? null) ?: $defaults->indexNoise,
            indexClip: self::number($data['index_clip'] ?? null, $defaults->indexClip),
            priorVariances: self::floats($data['prior_variances'] ?? null) ?: $defaults->priorVariances,
            brandPseudoPuzzles: self::number($data['brand_pseudo_puzzles'] ?? null, $defaults->brandPseudoPuzzles),
            centreOffsets: self::floats($data['centre_offsets'] ?? null),
            spreadWeight: self::number($firstTry['spread_weight'] ?? null, $defaults->spreadWeight),
            posteriorWeight: self::number($firstTry['posterior_weight'] ?? null, $defaults->posteriorWeight),
            sizeTerms: self::floats($firstTry['size_terms'] ?? null) ?: $defaults->sizeTerms,
            paceTerms: self::floats($firstTry['pace_terms'] ?? null),
            dispersionWeight: self::number($firstTry['dispersion_weight'] ?? null, $defaults->dispersionWeight),
            dispersionShrink: self::number($firstTry['dispersion_shrink'] ?? null, $defaults->dispersionShrink),
            contextScales: self::floats($firstTry['context_scales'] ?? null),
            unboxedOffset: self::number($firstTry['unboxed_offset'] ?? null, $defaults->unboxedOffset),
            consistencyEdges: self::edges($firstTry['consistency_edges'] ?? null, $defaults->consistencyEdges),
            quantiles: $quantiles,
            repeatSpreadWeight: self::number($repeat['spread_weight'] ?? null, $defaults->repeatSpreadWeight),
            repeatConstant: self::number($repeat['constant'] ?? null, $defaults->repeatConstant),
            repeatGapTerms: self::floats($repeat['gap_terms'] ?? null),
            repeatGapOffsets: self::floats($repeat['gap_offsets'] ?? null),
            repeatConsistencyEdges: self::edges($repeat['consistency_edges'] ?? null, $defaults->repeatConsistencyEdges),
            features: $features,
            fittedAt: is_string($data['fitted_at'] ?? null) ? new DateTimeImmutable($data['fitted_at']) : null,
            summary: $summary,
            thinSizes: $thinSizes,
        );
    }

    /**
     * @return array<string, float>
     */
    private static function floats(mixed $value): array
    {
        $floats = [];

        foreach (is_array($value) ? $value : [] as $key => $number) {
            if (is_string($key) && is_numeric($number)) {
                $floats[$key] = (float) $number;
            }
        }

        return $floats;
    }

    private static function number(mixed $value, float $default): float
    {
        return is_numeric($value) ? (float) $value : $default;
    }

    /**
     * @param list<float> $default
     * @return list<float>
     */
    private static function edges(mixed $value, array $default): array
    {
        if (!is_array($value) || count($value) !== 3) {
            return $default;
        }

        $edges = [];

        foreach ($value as $edge) {
            if (!is_numeric($edge)) {
                return $default;
            }

            $edges[] = (float) $edge;
        }

        return $edges;
    }
}
