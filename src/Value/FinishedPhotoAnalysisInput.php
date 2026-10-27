<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleImageAnalysis;

/**
 * One analysis of a player's finished-puzzle photo as the internal API received it, checked (docs/features/
 * internal-api.md, "Finished-puzzle photos"): `puzzleId`, `analyzer` one of ImageAnalyzer::finishedPhotoValues(),
 * `analyzerVersion` (≤ 50 characters), `photoFingerprint` (which photo - from the work list), `imageFingerprint` (the
 * catalogue picture the photo was matched against), `analyzedAt` ISO 8601 or null, `result` a JSON object of at most
 * the analyzer's maxResultBytes(). Whether the photo and the picture are still the puzzle's is the handler's check.
 * Every mistake is collected (InvalidPuzzleImageAnalysis - in a batch the item's `invalid` outcome).
 */
final readonly class FinishedPhotoAnalysisInput
{
    /** @var list<string> */
    public const array ITEM_FIELDS = ['puzzleId', 'analyzer', 'analyzerVersion', 'photoFingerprint', 'imageFingerprint', 'analyzedAt', 'result'];

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(
        public string $puzzleId,
        public ImageAnalyzer $analyzer,
        public string $analyzerVersion,
        public string $photoFingerprint,
        public string $imageFingerprint,
        public null|DateTimeImmutable $analyzedAt,
        public array $result,
    ) {
    }

    /**
     * One item of `POST /internal-api/finished-photo-analyses`.
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

        $analyzerValue = $item['analyzer'] ?? null;
        $analyzer = is_string($analyzerValue) ? ImageAnalyzer::tryFrom($analyzerValue) : null;

        if ($analyzer === null || $analyzer->describesCataloguePicture()) {
            $errors['analyzer'] = sprintf('must be one of: %s.', implode(', ', ImageAnalyzer::finishedPhotoValues()));
            $analyzer = null;
        }

        $version = AnalysisPayloadFields::requiredString($item, 'analyzerVersion', PuzzleImageAnalysisInput::MAX_VERSION_LENGTH, $errors);
        $photoFingerprint = AnalysisPayloadFields::requiredString($item, 'photoFingerprint', PuzzleImageAnalysisInput::MAX_FINGERPRINT_LENGTH, $errors);
        $imageFingerprint = AnalysisPayloadFields::requiredString($item, 'imageFingerprint', PuzzleImageAnalysisInput::MAX_FINGERPRINT_LENGTH, $errors);
        $analyzedAt = AnalysisPayloadFields::analyzedAt($item['analyzedAt'] ?? null, $errors);
        $result = AnalysisPayloadFields::result($item['result'] ?? null, $analyzer?->maxResultBytes() ?? ImageAnalyzer::DEFAULT_MAX_RESULT_BYTES, $errors);

        if ($errors !== [] || $analyzer === null || $version === null || $photoFingerprint === null || $imageFingerprint === null || $result === null) {
            throw new InvalidPuzzleImageAnalysis($errors);
        }

        return new self(strtolower($puzzleId), $analyzer, $version, $photoFingerprint, $imageFingerprint, $analyzedAt, $result);
    }
}
