<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class LendBorrowHistoryItem
{
    public function __construct(
        public string $transferId,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public null|string $puzzleAlternativeName,
        public null|int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public TransferType $transferType,
        public null|string $fromPlayerId,
        public null|string $fromPlayerName,
        public null|string $toPlayerId,
        public null|string $toPlayerName,
        public null|string $ownerPlayerId,
        public null|string $ownerPlayerName,
        public DateTimeImmutable $transferredAt,
        public bool $isActive,
    ) {
    }
}
