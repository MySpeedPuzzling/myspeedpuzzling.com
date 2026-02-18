<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;

readonly final class TransactionCompleted
{
    public function __construct(
        public UuidInterface $soldSwappedItemId,
        public UuidInterface $sellerId,
        public null|UuidInterface $buyerPlayerId,
    ) {
    }
}
