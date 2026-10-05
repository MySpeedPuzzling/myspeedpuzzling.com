<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class SubmitPuzzleChangeRequest
{
    public function __construct(
        public string $changeRequestId,
        public string $puzzleId,
        public string $reporterId,
        public string $proposedName,
        public null|string $proposedManufacturerId,
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
    ) {
    }
}
