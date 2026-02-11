<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RemoveListingReservation
{
    public function __construct(
        public string $sellSwapListItemId,
        public string $playerId,
    ) {
    }
}
