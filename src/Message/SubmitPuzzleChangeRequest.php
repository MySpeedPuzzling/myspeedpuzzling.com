<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Locked per puzzle like every change of its record: an image pipeline proposal is checked against the puzzle's
 * picture and the proposals filed before it (one per picture and tag, ever) - two at once must not both pass.
 */
readonly final class SubmitPuzzleChangeRequest implements SerializedByLock
{
    /**
     * @param list<string> $tags
     */
    public function __construct(
        public string $changeRequestId,
        public string $puzzleId,
        public string $reporterId,
        public string $proposedName,
        // A brand id, or a typed brand name - a name no brand matches creates the brand (ManufacturerResolver)
        public null|string $proposedBrand,
        public int $proposedPiecesCount,
        // Every code as it should end up - stored as proposed only when it differs from the puzzle's
        public EanList $proposedEans,
        public BrandCodeList $proposedBrandCodes,
        public null|UploadedFile $proposedPhoto,
        // The other names and the main title's language the proposal was made against (what the player saw) - the
        // names part is applied as a diff against them, so they are never read from the puzzle as it is by then
        public PuzzleNames $originalAlternativeNames,
        public null|string $originalNameLanguage,
        // The other names as they should end up - null = the names (and the main title's language) are not proposed
        public null|PuzzleNames $proposedAlternativeNames = null,
        // The main title's language as proposed (null = English or not known), with the other names only
        public null|string $proposedNameLanguage = null,
        public PuzzleChangeRequestSource $source = PuzzleChangeRequestSource::Player,
        // The internal API's metadata (docs/features/internal-api.md "Puzzle change requests") - the image pipeline
        // always sends the tags and the fingerprint of the picture it looked at
        public array $tags = [],
        public null|float $confidence = null,
        public null|string $modelVersion = null,
        // The puzzle's picture the proposal was made from (PuzzleImageFingerprint) - a puzzle with another one now
        // refuses it (PuzzleImageChangedMeanwhile)
        public null|string $sourceImageFingerprint = null,
    ) {
    }

    public function lockKey(): string
    {
        return PuzzleRecordVersion::lockKey($this->puzzleId);
    }
}
