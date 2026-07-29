<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\EmailNotificationFrequency;

readonly final class EditEmailPreferences
{
    public function __construct(
        public string $playerId,
        public bool $newsletterEnabled,
        public bool $emailNotificationsEnabled,
        public EmailNotificationFrequency $emailNotificationFrequency,
    ) {
    }
}
