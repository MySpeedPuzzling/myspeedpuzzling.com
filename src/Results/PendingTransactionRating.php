<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class PendingTransactionRating
{
    public function __construct(
        public string $soldSwappedItemId,
        public string $puzzleName,
        public null|string $puzzleImage,
        public null|int $piecesCount,
        public string $otherPlayerName,
        public string $otherPlayerCode,
        public null|string $otherPlayerAvatar,
        public string $transactionType,
        public DateTimeImmutable $soldAt,
    ) {
    }
}
