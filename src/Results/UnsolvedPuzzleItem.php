<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class UnsolvedPuzzleItem
{
    public function __construct(
        public string $puzzleId,
        public string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|string $puzzleIdentificationNumber,
        public null|string $ean,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public DateTimeImmutable $addedAt,
        public bool $isBorrowed,
        public null|string $borrowedFromPlayerId,
        public null|string $borrowedFromPlayerName,
    ) {
    }
}
