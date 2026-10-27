<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
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
        $errors = AnalysisPayloadFields::unknownFields($body, self::BODY_FIELDS);
        $analyzerCase = ImageAnalyzer::catalogue($analyzer);

        if ($analyzerCase === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::catalogueValues()));
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
        $errors = AnalysisPayloadFields::unknownFields($item, self::ITEM_FIELDS);

        $puzzleId = $item['puzzleId'] ?? null;

        if (is_string($puzzleId) === false || Uuid::isValid($puzzleId) === false) {
            $errors['puzzleId'] = 'must be a puzzle id.';
            $puzzleId = '';
        }

        $analyzer = $item['analyzer'] ?? null;
        $analyzerCase = is_string($analyzer) ? ImageAnalyzer::catalogue($analyzer) : null;

        if ($analyzerCase === null) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::catalogueValues()));
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
        $version = AnalysisPayloadFields::requiredString($fields, 'analyzerVersion', self::MAX_VERSION_LENGTH, $errors);
        $fingerprint = AnalysisPayloadFields::requiredString($fields, 'imageFingerprint', self::MAX_FINGERPRINT_LENGTH, $errors);
        $analyzedAt = AnalysisPayloadFields::analyzedAt($fields['analyzedAt'] ?? null, $errors);
        $result = AnalysisPayloadFields::result($fields['result'] ?? null, $analyzer?->maxResultBytes() ?? ImageAnalyzer::DEFAULT_MAX_RESULT_BYTES, $errors);

        if ($analyzer === ImageAnalyzer::Artwork && $result !== null) {
            self::checkArtwork($result, $errors);
        }

        if ($errors !== [] || $analyzer === null || $version === null || $fingerprint === null || $result === null) {
            throw new InvalidPuzzleImageAnalysis($errors);
        }

        return new self($puzzleId, $analyzer, $version, $fingerprint, $analyzedAt, $result);
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
