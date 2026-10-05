<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

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
        public null|string $proposedEan,
        public null|string $proposedIdentificationNumber,
        public null|UploadedFile $proposedPhoto,
        // The other names as they should end up - null = the names (and the main title's language) are not proposed
        public null|PuzzleNames $proposedAlternativeNames = null,
        // The main title's language as proposed (null = English or not known), with the other names only
        public null|string $proposedNameLanguage = null,
    ) {
    }
}
