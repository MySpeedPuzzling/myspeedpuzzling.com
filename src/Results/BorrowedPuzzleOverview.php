<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class BorrowedPuzzleOverview
{
    public function __construct(
        public string $lentPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public string $ownerId,
        public string $ownerName,
        public null|string $ownerAvatar,
        public null|string $notes,
        public DateTimeImmutable $lentAt,
    ) {
    }
}
