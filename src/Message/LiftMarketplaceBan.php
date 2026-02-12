<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class LiftMarketplaceBan
{
    public function __construct(
        public string $targetPlayerId,
        public string $adminId,
    ) {
    }
}
