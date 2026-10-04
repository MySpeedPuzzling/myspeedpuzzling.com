<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The puzzle as the reviewer approves a change request: every field, proposed by the player or not.
 */
readonly final class ReviewedPuzzleValues
{
    public function __construct(
        public string $name,
        public null|string $alternativeName,
        public null|string $manufacturerId,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public PuzzleChangeRequestImageChoice $image = PuzzleChangeRequestImageChoice::Keep,
        // Required for PuzzleChangeRequestImageChoice::Upload
        public null|UploadedFile $uploadedImage = null,
    ) {
    }
}
