<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use DateTimeZone;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleImageAnalysis;

/**
 * One image analysis as the internal API received it, checked (docs/features/internal-api.md, "Puzzle image
 * analysis"): the analyzer one of ImageAnalyzer, `analyzerVersion` a string of at most 50 characters,
 * `imageFingerprint` a string (whether it is the puzzle's current picture is the handler's check), `analyzedAt`
 * ISO 8601 or null (UTC when it has no offset; stored in UTC), `result` a JSON object of at most the analyzer's
 * maxResultBytes() (64 KB for now). The payload is the analyzer's own - only `artwork` is looked into: `bbox`
 * {x, y, w, h} and `corners` (4 points, {x, y} or [x, y]), when present, are fractions 0..1 of the source image, the box
 * inside it.
 *
 * Every mistake is collected, so one answer lists them all (InvalidPuzzleImageAnalysis: 422, in a batch the item's
 * `invalid` outcome). A field the endpoint does not know is a mistake too - a typo would otherwise be dropped unseen.
 */
final readonly class PuzzleImageAnalysisInput
{
    public const int MAX_VERSION_LENGTH = 50;

    public const int MAX_FINGERPRINT_LENGTH = 64;

    /** @var list<string> */
    public const array BODY_FIELDS = ['analyzerVersion', 'imageFingerprint', 'analyzedAt', 'result'];

    /** @var list<string> */
    public const array ITEM_FIELDS = ['puzzleId', 'analyzer', 'analyzerVersion', 'imageFingerprint', 'analyzedAt', 'result'];

    // Rounding of a float computed by a model (x + w = 1.0000000002) is not a box outside the image
    private const float TOLERANCE = 0.000001;

    private const string DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})?$/';

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public string $puzzleId,
        public ImageAnalyzer $analyzer,
        public string $analyzerVersion,
        public string $imageFingerprint,
        public null|DateTimeImmutable $analyzedAt,
        public array $result,
    ) {
    }

    /**
     * The body of `PUT /internal-api/puzzles/{puzzleId}/image-analysis/{analyzer}` - the puzzle and analyzer come from
     * the path.
     *
     * @param array<string, mixed> $body
     *
     * @throws InvalidPuzzleImageAnalysis
     */
    public static function fromBody(string $puzzleId, string $analyzer, array $body): self
    {
        $errors = self::unknownFields($body, self::BODY_FIELDS);
        $analyzerCase = ImageAnalyzer::tryFrom($analyzer);

        if ($analyzerCase === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::values()));
        }

        return self::build(strtolower($puzzleId), $analyzerCase, $body, $errors);
    }

    /**
     * One item of `POST /internal-api/puzzle-image-analyses` - the puzzle and analyzer are fields of it.
     *
     * @throws InvalidPuzzleImageAnalysis
     */
    public static function fromItem(mixed $item): self
    {
        if (is_array($item) === false || ($item !== [] && array_is_list($item))) {
            throw new InvalidPuzzleImageAnalysis(['item' => 'must be a JSON object.']);
        }

        /** @var array<string, mixed> $item */
        $errors = self::unknownFields($item, self::ITEM_FIELDS);

        $puzzleId = $item['puzzleId'] ?? null;

        if (is_string($puzzleId) === false || Uuid::isValid($puzzleId) === false) {
            $errors['puzzleId'] = 'must be a puzzle id.';
            $puzzleId = '';
        }

        $analyzer = $item['analyzer'] ?? null;
        $analyzerCase = is_string($analyzer) ? ImageAnalyzer::tryFrom($analyzer) : null;

        if ($analyzerCase === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::values()));
        }

        return self::build(strtolower($puzzleId), $analyzerCase, $item, $errors);
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $errors
     *
     * @throws InvalidPuzzleImageAnalysis
     */
    private static function build(string $puzzleId, null|ImageAnalyzer $analyzer, array $fields, array $errors): self
    {
        $version = self::requiredString($fields, 'analyzerVersion', self::MAX_VERSION_LENGTH, $errors);
        $fingerprint = self::requiredString($fields, 'imageFingerprint', self::MAX_FINGERPRINT_LENGTH, $errors);
        $analyzedAt = self::analyzedAt($fields['analyzedAt'] ?? null, $errors);
        $result = self::result($fields['result'] ?? null, $analyzer?->maxResultBytes() ?? ImageAnalyzer::DEFAULT_MAX_RESULT_BYTES, $errors);

        if ($analyzer === ImageAnalyzer::Artwork && $result !== null) {
            self::checkArtwork($result, $errors);
        }

        if ($errors !== [] || $analyzer === null || $version === null || $fingerprint === null || $result === null) {
            throw new InvalidPuzzleImageAnalysis($errors);
        }

        return new self($puzzleId, $analyzer, $version, $fingerprint, $analyzedAt, $result);
    }

    /**
     * @param array<string, mixed> $fields
     * @param list<string> $known
     *
     * @return array<string, string>
     */
    private static function unknownFields(array $fields, array $known): array
    {
        $errors = [];

        foreach (array_keys($fields) as $field) {
            if (in_array($field, $known, true) === false) {
                $errors[(string) $field] = sprintf('is not a field of this endpoint (known: %s).', implode(', ', $known));
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, string> $errors
     */
    private static function requiredString(array $fields, string $field, int $maxLength, array &$errors): null|string
    {
        $value = $fields[$field] ?? null;
        $value = is_string($value) ? trim($value) : null;

        if ($value === null || $value === '') {
            $errors[$field] = 'is required - a non-empty string.';

            return null;
        }

        if (mb_strlen($value) > $maxLength) {
            $errors[$field] = sprintf('can be at most %d characters long.', $maxLength);

            return null;
        }

        // Postgres text refuses NUL - found only at the flush, it would fail the whole batch with a 500
        if (str_contains($value, "\0")) {
            $errors[$field] = 'must not contain NUL characters.';

            return null;
        }

        return $value;
    }

    /**
     * @param array<string, string> $errors
     */
    private static function analyzedAt(mixed $value, array &$errors): null|DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) === false || preg_match(self::DATE_TIME_PATTERN, $value) !== 1) {
            $errors['analyzedAt'] = 'must be an ISO 8601 date-time, e.g. "2026-10-18T06:30:00Z", or null.';

            return null;
        }

        try {
            $moment = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\Exception) {
            $errors['analyzedAt'] = 'is not a real date-time.';

            return null;
        }

        // A day that does not exist (2026-02-30) rolls over silently - parsed in its own offset, the day must stay
        if ($moment->format('Y-m-d') !== substr($value, 0, 10)) {
            $errors['analyzedAt'] = 'is not a real date-time.';

            return null;
        }

        return $moment->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * @param array<string, string> $errors
     *
     * @return null|array<string, mixed>
     */
    private static function result(mixed $value, int $maxBytes, array &$errors): null|array
    {
        // A JSON object decodes to an array with string keys; [] would be stored as a JSON list
        if (is_array($value) === false || $value === [] || array_is_list($value)) {
            $errors['result'] = 'is required - a JSON object with at least one field.';

            return null;
        }

        // Postgres jsonb refuses \u0000 - found only at the flush, it would fail the whole batch with a 500
        if (self::containsNul($value)) {
            $errors['result'] = 'must not contain NUL characters.';

            return null;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($encoded === false) {
            $errors['result'] = 'cannot be stored as JSON.';

            return null;
        }

        if (strlen($encoded) > $maxBytes) {
            $errors['result'] = sprintf('can be at most %d bytes of JSON (it is %d).', $maxBytes, strlen($encoded));

            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * A NUL character in any key or string value, at any depth.
     *
     * @param array<mixed> $values
     */
    private static function containsNul(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && str_contains($key, "\0")) {
                return true;
            }

            if (is_string($value) && str_contains($value, "\0")) {
                return true;
            }

            if (is_array($value) && self::containsNul($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, string> $errors
     */
    private static function checkArtwork(array $result, array &$errors): void
    {
        $bbox = $result['bbox'] ?? null;

        if ($bbox !== null) {
            $box = is_array($bbox) ? self::fractions($bbox, ['x', 'y', 'w', 'h']) : null;

            if ($box === null) {
                $errors['result.bbox'] = 'must be {"x", "y", "w", "h"} - numbers from 0 to 1 (fractions of the image).';
            } elseif ($box['w'] <= 0 || $box['h'] <= 0) {
                $errors['result.bbox'] = 'must have a width and a height ("w", "h" above 0).';
            } elseif ($box['x'] + $box['w'] > 1 + self::TOLERANCE || $box['y'] + $box['h'] > 1 + self::TOLERANCE) {
                $errors['result.bbox'] = 'must lie inside the image (x + w and y + h at most 1).';
            }
        }

        $corners = $result['corners'] ?? null;

        if ($corners === null) {
            return;
        }

        if (is_array($corners) === false || array_is_list($corners) === false || count($corners) !== 4) {
            $errors['result.corners'] = 'must be a list of 4 points.';

            return;
        }

        foreach ($corners as $point) {
            $valid = is_array($point) && (array_is_list($point)
                ? count($point) === 2 && self::fractions($point, [0, 1]) !== null
                : self::fractions($point, ['x', 'y']) !== null);

            if ($valid === false) {
                $errors['result.corners'] = 'must be 4 points {"x", "y"} or [x, y] - numbers from 0 to 1 (fractions of the image).';

                return;
            }
        }
    }

    /**
     * The listed keys as numbers within 0..1 - null when one is missing or is no such number.
     *
     * @param array<mixed> $values
     * @param list<int|string> $keys
     *
     * @return null|array<int|string, float>
     */
    private static function fractions(array $values, array $keys): null|array
    {
        $fractions = [];

        foreach ($keys as $key) {
            $value = $values[$key] ?? null;

            if ((is_int($value) || is_float($value)) === false || $value < 0 || $value > 1 + self::TOLERANCE) {
                return null;
            }

            $fractions[$key] = (float) $value;
        }

        return $fractions;
    }
}
