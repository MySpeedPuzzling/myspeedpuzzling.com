<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UnblockUser
{
    public function __construct(
        public string $blockerId,
        public string $blockedId,
    ) {
    }
}
