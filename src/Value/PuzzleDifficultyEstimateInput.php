<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleDifficultyEstimate;

/**
 * One item of `PUT /internal-api/puzzle-difficulty-estimates`, checked (docs/features/internal-api.md, "Puzzle
 * difficulty labels and estimates"): `model` / `modelVersion` strings of at most 50 characters, `logDifficulty`,
 * `logP10`, `logP90` numbers within ±LOG_BOUND with logP10 ≤ logDifficulty ≤ logP90, `outOfFold` true/false,
 * `trainingSize` a whole number ≥ 1, `imageFingerprint` a string (whether it is the puzzle's current picture is the
 * handler's check), `computedAt` ISO 8601 or null. Every mistake is collected (InvalidPuzzleDifficultyEstimate - the
 * item's `invalid` outcome); a field the endpoint does not know is a mistake too - a typo would be dropped unseen.
 */
final readonly class PuzzleDifficultyEstimateInput
{
    use DifficultyEstimateFieldChecks;

    public const int MAX_NAME_LENGTH = 50;

    public const int MAX_FINGERPRINT_LENGTH = 64;

    // ln of a difficulty index - the batch caps indices at 5.0 (ln 1.61); anything beyond ±5 (×148) is a bug upstream
    public const float LOG_BOUND = 5.0;

    public const int MAX_TRAINING_SIZE = 10_000_000;

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
        }

        $outOfFold = $item['outOfFold'] ?? null;

        if (is_bool($outOfFold) === false) {
            $errors['outOfFold'] = 'is required - true or false.';
            $outOfFold = null;
        }

        $trainingSize = self::requiredInt($item, 'trainingSize', 1, self::MAX_TRAINING_SIZE, $errors);
        $fingerprint = self::requiredString($item, 'imageFingerprint', self::MAX_FINGERPRINT_LENGTH, $errors);
        $computedAt = self::optionalMoment($item['computedAt'] ?? null, 'computedAt', $errors);

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
        );
    }
}
