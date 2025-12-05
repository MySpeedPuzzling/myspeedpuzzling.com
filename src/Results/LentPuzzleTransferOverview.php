<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\TransferType;

readonly final class LentPuzzleTransferOverview
{
    public function __construct(
        public string $transferId,
        public null|string $fromPlayerId,
        public null|string $fromPlayerName,
        public null|string $toPlayerId,
        public null|string $toPlayerName,
        public DateTimeImmutable $transferredAt,
        public TransferType $transferType,
    ) {
    }
}
