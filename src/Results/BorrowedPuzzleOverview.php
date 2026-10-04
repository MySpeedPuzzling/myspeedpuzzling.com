<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\PuzzleNames;

readonly final class BorrowedPuzzleOverview
{
    public function __construct(
        public string $lentPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public null|float $imageRatio,
        public null|string $ownerId,
        public string $ownerName,
        public null|string $ownerAvatar,
        public null|string $notes,
        public DateTimeImmutable $lentAt,
    ) {
    }
}
