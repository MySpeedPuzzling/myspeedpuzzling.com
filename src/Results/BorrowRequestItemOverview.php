<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

final readonly class BorrowRequestItemOverview
{
    public function __construct(
        public string $itemId,
        public string $puzzleId,
        public string $puzzleName,
        public int $piecesCount,
        public null|string $manufacturerName,
        public null|string $image,
        public BorrowRequestItemStatus $status,
    ) {
    }
}
