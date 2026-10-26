<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BorrowRequestItemStatus;

/**
 * An open ask of the viewer for one puzzle on their wishlist (GetBorrowRequests::openOfAsker()): who was asked and
 * where it stands - waiting, or a yes waiting for the hand-over.
 */
final readonly class AskedBorrow
{
    public function __construct(
        public string $itemId,
        public string $requestId,
        public string $puzzleId,
        public string $ownerId,
        public null|string $ownerName,
        public string $ownerCode,
        public BorrowRequestItemStatus $status,
    ) {
    }

    public function ownerDisplayName(): string
    {
        return $this->ownerName ?? '#' . strtoupper($this->ownerCode);
    }
}
