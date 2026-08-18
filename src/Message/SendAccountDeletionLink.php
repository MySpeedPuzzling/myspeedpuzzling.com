<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class SendAccountDeletionLink
{
    public function __construct(
        public string $userId,
        #[SensitiveParameter]
        public string $token,
        public null|string $fallbackLocale,
    ) {
    }
}
