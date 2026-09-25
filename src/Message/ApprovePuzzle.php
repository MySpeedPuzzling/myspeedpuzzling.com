<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PuzzleApprovalBrandChoice;

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
    ) {
    }
}
