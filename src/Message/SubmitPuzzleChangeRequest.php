<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

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
    ) {
    }
}
