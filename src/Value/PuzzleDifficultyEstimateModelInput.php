<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleDifficultyEstimate;

/**
 * The body of `POST /internal-api/puzzle-difficulty-estimates/model`, checked (docs/features/internal-api.md, "Puzzle
 * difficulty labels and estimates"): `model` / `modelVersion` strings of at most 50 characters, `trainingSize` a whole
 * number ≥ 1, `holdoutSpearman` a number from -1 to 1 (the push guard's number), `evaluation` a JSON object of at most
 * MAX_EVALUATION_BYTES (the run's other numbers, stored as they came), `createdAt` ISO 8601 or null. Every mistake is
 * collected (InvalidPuzzleDifficultyEstimate, 422); an unknown field is one too.
 */
final readonly class PuzzleDifficultyEstimateModelInput
{
    use DifficultyEstimateFieldChecks;

    public const int MAX_EVALUATION_BYTES = 262_144;

    /** @var list<string> */
    public const array BODY_FIELDS = ['model', 'modelVersion', 'trainingSize', 'holdoutSpearman', 'evaluation', 'createdAt'];

    /**
     * @param array<string, mixed> $evaluation
     */
    public function __construct(
        public string $model,
        public string $modelVersion,
        public int $trainingSize,
        public float $holdoutSpearman,
        public array $evaluation,
        public null|DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidPuzzleDifficultyEstimate
     */
    public static function fromBody(array $body): self
    {
        $errors = self::unknownFields($body, self::BODY_FIELDS);
        $model = self::requiredString($body, 'model', PuzzleDifficultyEstimateInput::MAX_NAME_LENGTH, $errors);
        $modelVersion = self::requiredString($body, 'modelVersion', PuzzleDifficultyEstimateInput::MAX_NAME_LENGTH, $errors);
        $trainingSize = self::requiredInt($body, 'trainingSize', 1, PuzzleDifficultyEstimateInput::MAX_TRAINING_SIZE, $errors);
        $holdoutSpearman = self::requiredNumber($body, 'holdoutSpearman', -1.0, 1.0, $errors);
        $evaluation = self::evaluation($body['evaluation'] ?? null, $errors);
        $createdAt = self::optionalMoment($body['createdAt'] ?? null, 'createdAt', $errors);

        if ($errors !== [] || $model === null || $modelVersion === null || $trainingSize === null || $holdoutSpearman === null || $evaluation === null) {
            throw new InvalidPuzzleDifficultyEstimate($errors);
        }

        return new self($model, $modelVersion, $trainingSize, $holdoutSpearman, $evaluation, $createdAt);
    }

    /**
     * @param array<string, string> $errors
     *
     * @return null|array<string, mixed>
     */
    private static function evaluation(mixed $value, array &$errors): null|array
    {
        // A JSON object decodes to an array with string keys; [] would be stored as a JSON list
        if (is_array($value) === false || $value === [] || array_is_list($value)) {
            $errors['evaluation'] = 'is required - a JSON object with at least one field.';

            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            $errors['evaluation'] = 'cannot be stored as JSON.';

            return null;
        }

        // Postgres jsonb refuses \u0000 (in a key or a value, escaped by json_encode)
        if (str_contains($encoded, '\u0000')) {
            $errors['evaluation'] = 'must not contain NUL characters.';

            return null;
        }

        if (strlen($encoded) > self::MAX_EVALUATION_BYTES) {
            $errors['evaluation'] = sprintf('can be at most %d bytes of JSON (it is %d).', self::MAX_EVALUATION_BYTES, strlen($encoded));

            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }
}
