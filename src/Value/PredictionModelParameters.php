<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * The fitted part of the prediction model (docs/features/puzzle-intelligence/README.md, "Step 8: Time Prediction"):
 * refitted once a day by the insights batch (PredictionModelFitter) and stored as one JSON row per model version
 * (prediction_model_fit). defaults() holds the research's numbers (docs/features/insights/prediction-research.md
 * §12) for the time before the first fit and for parts a fit had too few samples for.
 *
 * - tau: pseudo-count of the prior in the shrunk difficulty of puzzles with 1-4 indices;
 * - thinSizes: per piece-count group, the size exponent and the number of own qualifying first attempts below which
 *   the player's 500-piece pace scaled by it replaces their own baseline (absent = the own baseline always wins);
 * - first tries: sigma² = playerSpreadWeight × spread² + puzzleWeight ÷ (k + 1) + sizeTerm(group);
 * - a prediction resting on the picture alone (TimePredictionCalculator::imageWindow()): each window end combines the
 *   player's part (z × the player variance above without the puzzle term) with the estimate's own quantile offset
 *   × a scale fitted per window (50 / 80 %), size group × image kind × k = 0 / 1-4 (imageWindowScales, keyed
 *   imageWindowKey(), fitted recency-weighted - PredictionModelFitter step 6). A stored fit from before them has none
 *   and keeps its symmetric form: a scale × the estimate's variance as the puzzle term of sigma (imageVarianceScale(s),
 *   read only for such a stored fit);
 * - repeats: sigma² = repeatSpreadWeight × spread² + repeatConstant;
 * - consistency edges: the quartile edges of the player spread (first tries / repeats);
 * - quantiles: standardised-residual quantiles 10 / 25 / 75 / 90 % per WindowBucket key (with fallbacks).
 */
readonly final class PredictionModelParameters
{
    /** The global spread (log units) a player's own spread is shrunk toward */
    public const float GLOBAL_SPREAD = 0.13;

    /** A sigma never below this (log units) - a window is never a point */
    public const float MINIMUM_SIGMA = 0.045;

    private const array RESEARCH_QUANTILES = [-1.17, -0.62, 0.58, 1.21];

    /** The standard normal's 10 / 25 / 75 / 90 % quantiles - the player's part of a picture-only window */
    public const array NORMAL_QUANTILES = [-ImagePriorInput::Z90, -ImagePriorInput::Z75, ImagePriorInput::Z75, ImagePriorInput::Z90];

    /** imageWindowKey() of the scale every picture-only window falls back to */
    public const string IMAGE_WINDOW_ALL = 'all';

    /**
     * @param array<string, array{alpha: float, max_own_solves: int}> $thinSizes keyed by PiecesGroup value
     * @param array<string, float> $firstTrySizeTerms keyed by PiecesGroup value
     * @param list<float> $firstTryConsistencyEdges
     * @param list<float> $repeatConsistencyEdges
     * @param array<string, array{0: float, 1: float, 2: float, 3: float}> $quantiles keyed by WindowBucket key
     * @param array<string, int|float|string> $summary what the fit rested on (sample sizes, the cut-off) - for people
     */
    public function __construct(
        public float $tau,
        public array $thinSizes,
        public float $firstTryPlayerSpreadWeight,
        public float $firstTryPuzzleWeight,
        public array $firstTrySizeTerms,
        public float $repeatSpreadWeight,
        public float $repeatConstant,
        public array $firstTryConsistencyEdges,
        public array $repeatConsistencyEdges,
        public array $quantiles,
        public null|DateTimeImmutable $fittedAt = null,
        public array $summary = [],
        public float $imageVarianceScale = 1.0,
        /** @var array<string, float> keyed "<PiecesGroup>|<ImageKind>" and "<PiecesGroup>" (imageVarianceScaleFor()) */
        public array $imageVarianceScales = [],
        /** @var array<string, array{0: float, 1: float}> the 50 % and the 80 % window's scale, keyed imageWindowKey() */
        public array $imageWindowScales = [],
    ) {
    }

    public static function defaults(): self
    {
        return new self(
            tau: 1.0,
            thinSizes: [],
            firstTryPlayerSpreadWeight: 0.20,
            firstTryPuzzleWeight: 0.049,
            firstTrySizeTerms: [
                PiecesGroup::UpTo300->value => 0.008,
                PiecesGroup::FiveHundred->value => 0.0072,
                PiecesGroup::Thousand->value => 0.0217,
                PiecesGroup::From1500->value => 0.0466,
                PiecesGroup::Odd->value => 0.0126,
            ],
            repeatSpreadWeight: 0.40,
            repeatConstant: 0.0013,
            firstTryConsistencyEdges: [0.115, 0.13, 0.15],
            repeatConsistencyEdges: [0.115, 0.13, 0.15],
            quantiles: [
                'f' => self::RESEARCH_QUANTILES,
                'r' => self::RESEARCH_QUANTILES,
            ],
        );
    }

    /**
     * A copy with the given parts replaced (the fit builds the model part by part).
     *
     * @param null|array<string, array{alpha: float, max_own_solves: int}> $thinSizes
     * @param null|array<string, float> $firstTrySizeTerms
     * @param null|list<float> $firstTryConsistencyEdges
     * @param null|list<float> $repeatConsistencyEdges
     * @param null|array<string, array{0: float, 1: float, 2: float, 3: float}> $quantiles
     * @param null|array<string, int|float|string> $summary
     * @param null|array<string, float> $imageVarianceScales
     * @param null|array<string, array{0: float, 1: float}> $imageWindowScales
     */
    public function with(
        null|float $tau = null,
        null|array $thinSizes = null,
        null|float $firstTryPlayerSpreadWeight = null,
        null|float $firstTryPuzzleWeight = null,
        null|array $firstTrySizeTerms = null,
        null|float $repeatSpreadWeight = null,
        null|float $repeatConstant = null,
        null|array $firstTryConsistencyEdges = null,
        null|array $repeatConsistencyEdges = null,
        null|array $quantiles = null,
        null|DateTimeImmutable $fittedAt = null,
        null|array $summary = null,
        null|float $imageVarianceScale = null,
        null|array $imageVarianceScales = null,
        null|array $imageWindowScales = null,
    ): self {
        return new self(
            tau: $tau ?? $this->tau,
            thinSizes: $thinSizes ?? $this->thinSizes,
            firstTryPlayerSpreadWeight: $firstTryPlayerSpreadWeight ?? $this->firstTryPlayerSpreadWeight,
            firstTryPuzzleWeight: $firstTryPuzzleWeight ?? $this->firstTryPuzzleWeight,
            firstTrySizeTerms: $firstTrySizeTerms ?? $this->firstTrySizeTerms,
            repeatSpreadWeight: $repeatSpreadWeight ?? $this->repeatSpreadWeight,
            repeatConstant: $repeatConstant ?? $this->repeatConstant,
            firstTryConsistencyEdges: $firstTryConsistencyEdges ?? $this->firstTryConsistencyEdges,
            repeatConsistencyEdges: $repeatConsistencyEdges ?? $this->repeatConsistencyEdges,
            quantiles: $quantiles ?? $this->quantiles,
            fittedAt: $fittedAt ?? $this->fittedAt,
            summary: $summary ?? $this->summary,
            imageVarianceScale: $imageVarianceScale ?? $this->imageVarianceScale,
            imageVarianceScales: $imageVarianceScales ?? $this->imageVarianceScales,
            imageWindowScales: $imageWindowScales ?? $this->imageWindowScales,
        );
    }

    /**
     * The picture-only window's scales [50 %, 80 %] for a size group, image kind and the puzzle's index count when
     * predicted: from the most specific fit to the least - group × kind × k bucket, group × k bucket, group × kind,
     * group, all. Null when the fit has none (a fit from before them, or too few first tries): the symmetric form
     * (imageVarianceScaleFor()) then.
     *
     * @return null|array{0: float, 1: float}
     */
    public function imageWindowScalesFor(PiecesGroup $group, null|ImageKind $kind, int $k): null|array
    {
        if ($this->imageWindowScales === []) {
            return null;
        }

        foreach (self::imageWindowKeys($group, $kind, $k) as $key) {
            if (isset($this->imageWindowScales[$key])) {
                return $this->imageWindowScales[$key];
            }
        }

        return null;
    }

    /**
     * Every key a picture-only first try counts in, the most specific first (imageWindowScalesFor() reads them in
     * this order, the fit fills them all).
     *
     * @return list<string>
     */
    public static function imageWindowKeys(PiecesGroup $group, null|ImageKind $kind, int $k): array
    {
        $keys = [];

        if ($kind !== null) {
            $keys[] = self::imageWindowKey($group, $kind, $k);
        }

        $keys[] = self::imageWindowKey($group, null, $k);

        if ($kind !== null) {
            $keys[] = self::imageWindowKey($group, $kind);
        }

        $keys[] = self::imageWindowKey($group);
        $keys[] = self::IMAGE_WINDOW_ALL;

        return $keys;
    }

    /**
     * "<PiecesGroup>[|<ImageKind>][|k0 or k1-4]" - k = 0: nobody had solved the puzzle (its first solvers, the
     * predictions a picture-only estimate is shown for); 1-4: later solvers of a puzzle still without a difficulty.
     */
    public static function imageWindowKey(PiecesGroup $group, null|ImageKind $kind = null, null|int $k = null): string
    {
        return $group->value
            . ($kind !== null ? '|' . $kind->value : '')
            . ($k !== null ? ($k <= 0 ? '|k0' : '|k1-4') : '');
    }

    /**
     * The player's part of a first try's variance: sigma² without the puzzle term (firstTrySigma()).
     */
    public function firstTryPlayerVariance(float $playerSpread, PiecesGroup $group): float
    {
        return max(0.0, $this->firstTryPlayerSpreadWeight * $playerSpread ** 2 + ($this->firstTrySizeTerms[$group->value] ?? 0.0));
    }

    /**
     * The picture-only window's scale for a size group and image kind: its own fit, else the size group's, else the
     * global one.
     */
    public function imageVarianceScaleFor(PiecesGroup $group, null|ImageKind $kind): float
    {
        return $this->imageVarianceScales[self::imageScaleKey($group, $kind)]
            ?? $this->imageVarianceScales[self::imageScaleKey($group)]
            ?? $this->imageVarianceScale;
    }

    public static function imageScaleKey(PiecesGroup $group, null|ImageKind $kind = null): string
    {
        return $kind === null ? $group->value : $group->value . '|' . $kind->value;
    }

    /**
     * @param null|float $puzzleVariance the puzzle term when the difficulty's own uncertainty is known (a prediction
     *                                   resting on the image estimate alone: its interval, × the scale of
     *                                   imageVarianceScaleFor($group, $imageKind)) instead of b ÷ (k + 1)
     */
    public function firstTrySigma(float $playerSpread, int $k, PiecesGroup $group, null|float $puzzleVariance = null, null|ImageKind $imageKind = null): float
    {
        $variance = $this->firstTryPlayerSpreadWeight * $playerSpread ** 2
            + ($puzzleVariance !== null ? $this->imageVarianceScaleFor($group, $imageKind) * $puzzleVariance : $this->firstTryPuzzleWeight / (max(0, $k) + 1))
            + ($this->firstTrySizeTerms[$group->value] ?? 0.0);

        return max(sqrt(max($variance, 0.0)), self::MINIMUM_SIGMA);
    }

    public function repeatSigma(float $playerSpread): float
    {
        $variance = $this->repeatSpreadWeight * $playerSpread ** 2 + $this->repeatConstant;

        return max(sqrt(max($variance, 0.0)), self::MINIMUM_SIGMA);
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} q10, q25, q75, q90
     */
    public function quantilesFor(WindowBucket $bucket): array
    {
        foreach ($bucket->fallbackKeys() as $key) {
            if (isset($this->quantiles[$key])) {
                return $this->quantiles[$key];
            }
        }

        return self::RESEARCH_QUANTILES;
    }

    /**
     * @return null|array{alpha: float, max_own_solves: int}
     */
    public function thinSizeRule(PiecesGroup $group): null|array
    {
        return $this->thinSizes[$group->value] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tau' => $this->tau,
            'thin_sizes' => $this->thinSizes,
            'first_try' => [
                'player_spread_weight' => $this->firstTryPlayerSpreadWeight,
                'puzzle_weight' => $this->firstTryPuzzleWeight,
                'size_terms' => $this->firstTrySizeTerms,
                'consistency_edges' => $this->firstTryConsistencyEdges,
                'image_variance_scale' => $this->imageVarianceScale,
                'image_variance_scales' => $this->imageVarianceScales,
                'image_window_scales' => $this->imageWindowScales,
            ],
            'repeat' => [
                'spread_weight' => $this->repeatSpreadWeight,
                'constant' => $this->repeatConstant,
                'consistency_edges' => $this->repeatConsistencyEdges,
            ],
            'quantiles' => $this->quantiles,
            'fitted_at' => $this->fittedAt?->format(DATE_ATOM),
            'summary' => $this->summary,
        ];
    }

    /**
     * Whatever the stored row lacks comes from defaults() - an older or partial fit still loads.
     *
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $defaults = self::defaults();

        /** @var array<string, mixed> $firstTry */
        $firstTry = is_array($data['first_try'] ?? null) ? $data['first_try'] : [];
        /** @var array<string, mixed> $repeat */
        $repeat = is_array($data['repeat'] ?? null) ? $data['repeat'] : [];

        $thinSizes = [];

        foreach (is_array($data['thin_sizes'] ?? null) ? $data['thin_sizes'] : [] as $group => $rule) {
            if (is_string($group) && is_array($rule) && is_numeric($rule['alpha'] ?? null) && is_numeric($rule['max_own_solves'] ?? null)) {
                $thinSizes[$group] = ['alpha' => (float) $rule['alpha'], 'max_own_solves' => (int) $rule['max_own_solves']];
            }
        }

        $sizeTerms = $defaults->firstTrySizeTerms;

        foreach (is_array($firstTry['size_terms'] ?? null) ? $firstTry['size_terms'] : [] as $group => $term) {
            if (is_string($group) && is_numeric($term)) {
                $sizeTerms[$group] = (float) $term;
            }
        }

        $quantiles = [];

        foreach (is_array($data['quantiles'] ?? null) ? $data['quantiles'] : [] as $key => $values) {
            if (is_string($key) && is_array($values) && count($values) === 4) {
                $values = array_values($values);
                $quantiles[$key] = [(float) $values[0], (float) $values[1], (float) $values[2], (float) $values[3]]; // @phpstan-ignore cast.double, cast.double, cast.double, cast.double
            }
        }

        $imageScales = [];

        foreach (is_array($firstTry['image_variance_scales'] ?? null) ? $firstTry['image_variance_scales'] : [] as $key => $scale) {
            if (is_string($key) && is_numeric($scale)) {
                $imageScales[$key] = (float) $scale;
            }
        }

        $windowScales = [];

        foreach (is_array($firstTry['image_window_scales'] ?? null) ? $firstTry['image_window_scales'] : [] as $key => $pair) {
            if (is_string($key) && is_array($pair) && count($pair) === 2) {
                $pair = array_values($pair);

                if (is_numeric($pair[0]) && is_numeric($pair[1])) {
                    $windowScales[$key] = [(float) $pair[0], (float) $pair[1]];
                }
            }
        }

        $summary = [];

        foreach (is_array($data['summary'] ?? null) ? $data['summary'] : [] as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                $summary[$key] = $value;
            }
        }

        return new self(
            tau: is_numeric($data['tau'] ?? null) ? (float) $data['tau'] : $defaults->tau,
            thinSizes: $thinSizes,
            firstTryPlayerSpreadWeight: self::number($firstTry['player_spread_weight'] ?? null, $defaults->firstTryPlayerSpreadWeight),
            firstTryPuzzleWeight: self::number($firstTry['puzzle_weight'] ?? null, $defaults->firstTryPuzzleWeight),
            firstTrySizeTerms: $sizeTerms,
            repeatSpreadWeight: self::number($repeat['spread_weight'] ?? null, $defaults->repeatSpreadWeight),
            repeatConstant: self::number($repeat['constant'] ?? null, $defaults->repeatConstant),
            firstTryConsistencyEdges: self::edges($firstTry['consistency_edges'] ?? null, $defaults->firstTryConsistencyEdges),
            repeatConsistencyEdges: self::edges($repeat['consistency_edges'] ?? null, $defaults->repeatConsistencyEdges),
            quantiles: $quantiles !== [] ? $quantiles : $defaults->quantiles,
            fittedAt: is_string($data['fitted_at'] ?? null) ? new DateTimeImmutable($data['fitted_at']) : null,
            summary: $summary,
            imageVarianceScale: self::number($firstTry['image_variance_scale'] ?? null, $defaults->imageVarianceScale),
            imageVarianceScales: $imageScales,
            imageWindowScales: $windowScales,
        );
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
