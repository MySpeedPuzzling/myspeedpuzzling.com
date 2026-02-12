<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UnmuteUser
{
    public function __construct(
        public string $targetPlayerId,
        public string $adminId,
    ) {
    }
}
