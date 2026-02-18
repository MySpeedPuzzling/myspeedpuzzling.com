<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class BanFromMarketplace
{
    public function __construct(
        public string $targetPlayerId,
        public string $adminId,
        public string $reason,
        public null|string $reportId = null,
    ) {
    }
}
