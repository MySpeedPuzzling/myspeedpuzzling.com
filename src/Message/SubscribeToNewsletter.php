<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SubscribeToNewsletter
{
    public function __construct(
        public string $email,
        public string $locale,
        public null|string $ipAddress,
    ) {
    }
}
