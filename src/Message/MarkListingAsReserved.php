<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class MarkListingAsReserved
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $playerId,
        public null|string $reservedForPlayerId = null,
    ) {
    }
}
