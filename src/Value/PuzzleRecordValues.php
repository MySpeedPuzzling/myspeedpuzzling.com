<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * A puzzle's catalogue record as a moderator saves it - every field, whether it changes or not.
 * Applied by PuzzleRecordUpdater, for a change request approval and a direct edit alike.
 */
readonly final class PuzzleRecordValues
{
    public function __construct(
        public string $name,
        public null|string $alternativeName,
        public null|string $manufacturerId,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public PuzzleImageChoice $image = PuzzleImageChoice::Keep,
        // Required for PuzzleImageChoice::Upload
        public null|UploadedFile $uploadedImage = null,
    ) {
    }
}
