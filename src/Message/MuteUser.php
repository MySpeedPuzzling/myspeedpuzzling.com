<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class MuteUser
{
    public function __construct(
        public string $targetPlayerId,
        public string $adminId,
        public int $days,
        public string $reason,
        public null|string $reportId = null,
    ) {
    }
}
