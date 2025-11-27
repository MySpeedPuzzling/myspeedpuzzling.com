<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class LentPuzzleOverview
{
    public function __construct(
        public string $lentPuzzleId,
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public string $currentHolderId,
        public string $currentHolderName,
        public null|string $currentHolderAvatar,
        public null|string $notes,
        public DateTimeImmutable $lentAt,
    ) {
    }
}
