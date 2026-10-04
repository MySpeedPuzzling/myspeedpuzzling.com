<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly final class ApprovePuzzle
{
    public function __construct(
        public string $puzzleId,
        public string $reviewerId,
        public string $name,
        public int $piecesCount,
        public null|string $ean,
        public null|string $identificationNumber,
        public PuzzleApprovalBrandChoice $brandChoice = PuzzleApprovalBrandChoice::Keep,
        // Target brand for UseExisting and MergeInto
        public null|string $targetManufacturerId = null,
        // The alternative name as approved - null / blank leaves the puzzle without one
        public null|string $alternativeName = null,
        // A photo of the box the moderator uploads instead of the player's
        public null|UploadedFile $uploadedImage = null,
        // Why - kept in the puzzle's history
        public null|string $note = null,
    ) {
    }
}
