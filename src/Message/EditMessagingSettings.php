<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditMessagingSettings
{
    public function __construct(
        public string $playerId,
        public bool $allowDirectMessages,
        public bool $emailNotificationsEnabled,
    ) {
    }
}
