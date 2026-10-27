<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleDifficultyEstimate;

/**
 * One item of `PUT /internal-api/puzzle-difficulty-estimates`, checked (docs/features/internal-api.md, "Puzzle
 * difficulty labels and estimates"): `model` / `modelVersion` strings of at most 50 characters, `logDifficulty`,
 * `logP10`, `logP90` numbers within ±LOG_BOUND with logP10 ≤ logDifficulty ≤ logP90 and logP90 − logP10 at most
 * MAX_INTERVAL_WIDTH, `outOfFold` true/false,
 * `trainingSize` a whole number ≥ 1, `imageFingerprint` a string (whether it is the puzzle's current picture is the
 * handler's check), `computedAt` ISO 8601 or null. Optional (absent or null, since difficulty_prior 1.2.0):
 * `logP25` + `logP75` together, logP10 ≤ logP25 ≤ logDifficulty ≤ logP75 ≤ logP90; `pixelsPerPiece` above 0 up to
 * MAX_PIXELS_PER_PIECE; `cropTrust` 0-1; `pictureFactors` exactly PICTURE_FACTORS numbers within ±MAX_PICTURE_FACTOR
 * with `pictureFactorsVersion` (a string of at most 50 characters - the one without the other is a mistake). Every
 * mistake is collected (InvalidPuzzleDifficultyEstimate - the item's `invalid` outcome); a field the endpoint does not
 * know is a mistake too - a typo would be dropped unseen.
 */
final readonly class PuzzleDifficultyEstimateInput
{
    use DifficultyEstimateFieldChecks;

    public const int MAX_NAME_LENGTH = 50;

    public const int MAX_FINGERPRINT_LENGTH = 64;

    // ln of a difficulty index - the batch caps indices at 5.0 (ln 1.61); anything beyond ±5 (×148) is a bug upstream
    public const float LOG_BOUND = 5.0;

    /**
     * The widest 80 % interval an estimate may carry (log units): the rated puzzles' own difficulty spreads 0.44 between
     * its 10th and 90th percentile and 0.93 between the 1st and 99th (dev copy of production, 2026-10-19, 6,285 puzzles
     * with 5+ indices) - an interval of 1.5 says less than "some puzzle", so it is a bug upstream. Its width becomes the
     * window of the picture-only prediction (ImagePriorInput::variance()): bounded here, a pushed number can never blow
     * a window up.
     */
    public const float MAX_INTERVAL_WIDTH = 1.5;

    public const int MAX_TRAINING_SIZE = 10_000_000;

    // The side of one piece in pixels of the original picture - a 10,000 px picture of a 2-piece puzzle stays below
    public const float MAX_PIXELS_PER_PIECE = 100_000.0;

    // Picture factors: a whitened PCA basis (unit variance over the catalogue) - a factor beyond ±100 SD is a bug upstream
    public const int PICTURE_FACTORS = 16;

    public const float MAX_PICTURE_FACTOR = 100.0;

    // A model's float rounding (p10 = 0.1200000001 above the estimate 0.12) is no inverted interval
    private const float TOLERANCE = 0.000001;

    /** @var list<string> */
    public const array ITEM_FIELDS = [
        'puzzleId',
        'model',
        'modelVersion',
        'logDifficulty',
        'logP10',
        'logP90',
        'outOfFold',
        'trainingSize',
        'imageFingerprint',
        'computedAt',
        'logP25',
        'logP75',
        'pixelsPerPiece',
        'cropTrust',
        'pictureFactors',
        'pictureFactorsVersion',
    ];

    public function __construct(
        public string $puzzleId,
        public string $model,
        public string $modelVersion,
        public float $logDifficulty,
        public float $logP10,
        public float $logP90,
        public bool $outOfFold,
        public int $trainingSize,
        public string $imageFingerprint,
        public null|DateTimeImmutable $computedAt,
        public null|float $logP25 = null,
        public null|float $logP75 = null,
        public null|float $pixelsPerPiece = null,
        public null|float $cropTrust = null,
        /** @var null|list<float> */
        public null|array $pictureFactors = null,
        public null|string $pictureFactorsVersion = null,
    ) {
    }

    /**
     * @throws InvalidPuzzleDifficultyEstimate
     */
    public static function fromItem(mixed $item): self
    {
        if (is_array($item) === false || ($item !== [] && array_is_list($item))) {
            throw new InvalidPuzzleDifficultyEstimate(['item' => 'must be a JSON object.']);
        }

        $errors = self::unknownFields($item, self::ITEM_FIELDS);

        $puzzleId = $item['puzzleId'] ?? null;

        if (is_string($puzzleId) === false || Uuid::isValid($puzzleId) === false) {
            $errors['puzzleId'] = 'must be a puzzle id.';
            $puzzleId = null;
        }

        $model = self::requiredString($item, 'model', self::MAX_NAME_LENGTH, $errors);
        $modelVersion = self::requiredString($item, 'modelVersion', self::MAX_NAME_LENGTH, $errors);
        $logDifficulty = self::requiredNumber($item, 'logDifficulty', -self::LOG_BOUND, self::LOG_BOUND, $errors);
        $logP10 = self::requiredNumber($item, 'logP10', -self::LOG_BOUND, self::LOG_BOUND, $errors);
        $logP90 = self::requiredNumber($item, 'logP90', -self::LOG_BOUND, self::LOG_BOUND, $errors);

        if ($logDifficulty !== null && $logP10 !== null && $logP10 > $logDifficulty + self::TOLERANCE) {
            $errors['logP10'] = 'must not be above logDifficulty.';
        }

        if ($logDifficulty !== null && $logP90 !== null && $logP90 < $logDifficulty - self::TOLERANCE) {
            $errors['logP90'] = 'must not be below logDifficulty.';
        } elseif ($logP10 !== null && $logP90 !== null && $logP90 - $logP10 > self::MAX_INTERVAL_WIDTH + self::TOLERANCE) {
            $errors['logP90'] = sprintf('the 80 %% interval (logP90 - logP10) can be at most %s wide.', self::MAX_INTERVAL_WIDTH);
        }

        $outOfFold = $item['outOfFold'] ?? null;

        if (is_bool($outOfFold) === false) {
            $errors['outOfFold'] = 'is required - true or false.';
            $outOfFold = null;
        }

        $trainingSize = self::requiredInt($item, 'trainingSize', 1, self::MAX_TRAINING_SIZE, $errors);
        $fingerprint = self::requiredString($item, 'imageFingerprint', self::MAX_FINGERPRINT_LENGTH, $errors);
        $computedAt = self::optionalMoment($item['computedAt'] ?? null, 'computedAt', $errors);

        $logP25 = self::optionalNumber($item, 'logP25', -self::LOG_BOUND, self::LOG_BOUND, $errors);
        $logP75 = self::optionalNumber($item, 'logP75', -self::LOG_BOUND, self::LOG_BOUND, $errors);
        self::checkQuartiles($item, $logP10, $logP25, $logDifficulty, $logP75, $logP90, $errors);
        $pixelsPerPiece = self::optionalNumber($item, 'pixelsPerPiece', 0.0, self::MAX_PIXELS_PER_PIECE, $errors);

        if ($pixelsPerPiece !== null && $pixelsPerPiece <= 0.0) {
            $errors['pixelsPerPiece'] = 'must be above 0, or null.';
            $pixelsPerPiece = null;
        }

        $cropTrust = self::optionalNumber($item, 'cropTrust', 0.0, 1.0, $errors);
        [$pictureFactors, $pictureFactorsVersion] = self::pictureFactors($item, $errors);

        if (
            $errors !== []
            || $puzzleId === null
            || $model === null
            || $modelVersion === null
            || $logDifficulty === null
            || $logP10 === null
            || $logP90 === null
            || $outOfFold === null
            || $trainingSize === null
            || $fingerprint === null
        ) {
            throw new InvalidPuzzleDifficultyEstimate($errors);
        }

        return new self(
            puzzleId: strtolower($puzzleId),
            model: $model,
            modelVersion: $modelVersion,
            logDifficulty: $logDifficulty,
            logP10: $logP10,
            logP90: $logP90,
            outOfFold: $outOfFold,
            trainingSize: $trainingSize,
            imageFingerprint: $fingerprint,
            computedAt: $computedAt,
            logP25: $logP25,
            logP75: $logP75,
            pixelsPerPiece: $pixelsPerPiece,
            cropTrust: $cropTrust,
            pictureFactors: $pictureFactors,
            pictureFactorsVersion: $pictureFactorsVersion,
        );
    }

    /**
     * The 50 % interval comes whole and sits inside the 80 % one: logP10 ≤ logP25 ≤ logDifficulty ≤ logP75 ≤ logP90.
     *
     * @param array<mixed> $item
     * @param array<string, string> $errors
     */
    private static function checkQuartiles(array $item, null|float $p10, null|float $p25, null|float $estimate, null|float $p75, null|float $p90, array &$errors): void
    {
        if (isset($errors['logP25']) || isset($errors['logP75'])) {
            return;
        }

        if ((($item['logP25'] ?? null) === null) !== (($item['logP75'] ?? null) === null)) {
            $errors[($item['logP25'] ?? null) === null ? 'logP25' : 'logP75'] = 'logP25 and logP75 come together (the 50 % interval), or neither.';

            return;
        }

        if ($p25 === null || $p75 === null || $p10 === null || $estimate === null || $p90 === null) {
            return;
        }

        if ($p25 < $p10 - self::TOLERANCE || $p25 > $estimate + self::TOLERANCE) {
            $errors['logP25'] = 'must be from logP10 to logDifficulty.';
        }

        if ($p75 < $estimate - self::TOLERANCE || $p75 > $p90 + self::TOLERANCE) {
            $errors['logP75'] = 'must be from logDifficulty to logP90.';
        }
    }

    /**
     * @param array<mixed> $item
     * @param array<string, string> $errors
     *
     * @return array{null|list<float>, null|string}
     */
    private static function pictureFactors(array $item, array &$errors): array
    {
        $factors = $item['pictureFactors'] ?? null;
        $version = ($item['pictureFactorsVersion'] ?? null) === null ? null : self::requiredString($item, 'pictureFactorsVersion', self::MAX_NAME_LENGTH, $errors);

        if ($factors === null) {
            if ($version !== null) {
                $errors['pictureFactorsVersion'] = 'comes only with pictureFactors.';
            }

            return [null, null];
        }

        $valid = is_array($factors) && array_is_list($factors) && count($factors) === self::PICTURE_FACTORS;
        $values = [];

        foreach ($valid ? $factors : [] as $factor) {
            if ((is_int($factor) || is_float($factor)) === false || abs($factor) > self::MAX_PICTURE_FACTOR) {
                $valid = false;

                break;
            }

            $values[] = (float) $factor;
        }

        if ($valid === false) {
            $errors['pictureFactors'] = sprintf('must be a list of %d numbers from -%s to %s, or null.', self::PICTURE_FACTORS, self::MAX_PICTURE_FACTOR, self::MAX_PICTURE_FACTOR);

            return [null, null];
        }

        if ($version === null) {
            $errors['pictureFactorsVersion'] ??= 'is required with pictureFactors - the version of the basis they come from.';

            return [null, null];
        }

        return [$values, $version];
    }
}
