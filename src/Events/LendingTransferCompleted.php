<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Events;

use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class LendingTransferCompleted
{
    public function __construct(
        public UuidInterface $transferId,
        public UuidInterface $puzzleId,
        public TransferType $transferType,
        public UuidInterface $actingPlayerId,
        public null|UuidInterface $fromPlayerId = null,
        public null|UuidInterface $toPlayerId = null,
        public null|UuidInterface $ownerPlayerId = null,
    ) {
    }
}
