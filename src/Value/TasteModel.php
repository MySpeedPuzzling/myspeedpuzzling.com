<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The candidate model's taste factor (v5 - docs/features/puzzle-intelligence/README.md "Step 8", "The taste factor"):
 * every player solves some pictures relatively faster or slower than their pace and the puzzle's difficulty say.
 *
 *   ln t += b_i + u_i · W x_j  (+ c_i at an event)
 *
 * x_j = the puzzle's features (features(): its 16 picture factors, its kind, its size group), W = RANK × FEATURES
 * fitted once a day on every player's residuals (Model\TasteFitter - the players' data never leaves the server);
 * [b_i, u_i] = the player's ridge solution A⁻¹ g over their own earlier first tries (TasteState, closed form, shrunk to 0
 * by lambdaB / lambdaU), c_i their shrunk offset at events. The window adds the term's variance vᵀ A⁻¹ v.
 */
readonly final class TasteModel
{
    public const int RANK = 4;
    /** v7: the player's difficulty slope (TasteState::slopeTerm()) - its ridge (in Σ (d - mean)² units) and the first tries it needs; measured on both time splits (README "Candidate v7") */
    public const float SLOPE_LAMBDA = 2.0;
    public const int SLOPE_MINIMUM = 5;
    /** 16 picture factors, box, assembled, ≤ 300 pieces, 1000 pieces, other sizes than 500 */
    public const int FEATURES = PuzzleDifficultyEstimateInput::PICTURE_FACTORS + 5;

    /**
     * @param list<list<float>> $weights W, RANK rows of FEATURES
     * @param array<string, int|float|string> $summary
     */
    public function __construct(
        public string $factorsVersion,
        public array $weights,
        public float $lambdaU,
        public float $lambdaB,
        public float $competitionShrink,
        public array $summary = [],
    ) {
    }

    /**
     * x_j: the picture factors (zeros without them, or from another basis version than W was fitted on), the kind
     * (box, assembled; flat and the rest = 0) and the size group (≤ 300, 1000, any other but 500).
     *
     * @return list<float>
     */
    public static function features(null|PictureFactors $picture, int $piecesCount, null|string $factorsVersion): array
    {
        $factors = $picture !== null && $picture->factors !== null && $picture->version === $factorsVersion
            ? $picture->factors
            : array_fill(0, PuzzleDifficultyEstimateInput::PICTURE_FACTORS, 0.0);
        $kind = $picture->kind ?? ImageKind::Unknown;
        $group = PiecesGroup::fromPiecesCount($piecesCount);

        return [
            ...$factors,
            $kind === ImageKind::Box ? 1.0 : 0.0,
            $kind === ImageKind::Assembled ? 1.0 : 0.0,
            $group === PiecesGroup::UpTo300 ? 1.0 : 0.0,
            $group === PiecesGroup::Thousand ? 1.0 : 0.0,
            $group === PiecesGroup::From1500 || $group === PiecesGroup::Odd ? 1.0 : 0.0,
        ];
    }

    /**
     * The puzzle's vector in the player's space: [1 (the player's intercept), W x].
     *
     * @return list<float>
     */
    public function vector(null|PictureFactors $picture, int $piecesCount): array
    {
        return self::project($this->weights, self::features($picture, $piecesCount, $this->factorsVersion));
    }

    /**
     * @param list<list<float>> $weights
     * @param list<float> $features
     * @return list<float>
     */
    public static function project(array $weights, array $features): array
    {
        $vector = [1.0];

        foreach ($weights as $row) {
            $sum = self::factorSum($row, $features);

            for ($j = PuzzleDifficultyEstimateInput::PICTURE_FACTORS; $j < count($row); $j++) {
                $sum += $row[$j] * $features[$j];
            }

            $vector[] = $sum;
        }

        return $vector;
    }

    /**
     * Each row's sum over the picture factors alone - the first PICTURE_FACTORS terms of project()'s sums, the very same
     * operations (project() goes on from them): what the Puzzle Picker's SQL reads stored per picture
     * (candidate_picture_projection, CandidatePictureProjections) instead of 64 multiply-adds per puzzle and request.
     *
     * @param list<list<float>> $weights
     * @param list<float> $features
     * @return list<float>
     */
    public static function factorSums(array $weights, array $features): array
    {
        return array_map(static fn (array $row): float => self::factorSum($row, $features), $weights);
    }

    /**
     * @param list<float> $row
     * @param list<float> $features
     */
    private static function factorSum(array $row, array $features): float
    {
        $sum = 0.0;

        for ($j = 0; $j < PuzzleDifficultyEstimateInput::PICTURE_FACTORS; $j++) {
            $sum += $row[$j] * $features[$j];
        }

        return $sum;
    }

    /**
     * The prior a player's state starts from: A = diag(lambdaB, lambdaU, …), g = 0 - nothing known, every term 0.
     */
    public function initialState(): TasteState
    {
        return TasteState::initial($this->lambdaB, $this->lambdaU, count($this->weights));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'factors_version' => $this->factorsVersion,
            'weights' => $this->weights,
            'lambda_u' => $this->lambdaU,
            'lambda_b' => $this->lambdaB,
            'competition_shrink' => $this->competitionShrink,
            'summary' => $this->summary,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): null|self
    {
        $weights = [];

        foreach (is_array($data['weights'] ?? null) ? $data['weights'] : [] as $row) {
            if (!is_array($row) || count($row) !== self::FEATURES) {
                return null;
            }

            $weights[] = array_map(static fn (mixed $value): float => is_numeric($value) ? (float) $value : 0.0, array_values($row));
        }

        if ($weights === [] || !is_string($data['factors_version'] ?? null) || !is_numeric($data['lambda_u'] ?? null) || !is_numeric($data['lambda_b'] ?? null)) {
            return null;
        }

        $summary = [];

        foreach (is_array($data['summary'] ?? null) ? $data['summary'] : [] as $key => $value) {
            if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                $summary[$key] = $value;
            }
        }

        return new self(
            $data['factors_version'],
            $weights,
            (float) $data['lambda_u'],
            (float) $data['lambda_b'],
            is_numeric($data['competition_shrink'] ?? null) ? (float) $data['competition_shrink'] : 10.0,
            $summary,
        );
    }
}
