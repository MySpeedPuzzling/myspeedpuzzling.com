<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class ChangeAccountEmail
{
    public function __construct(
        public string $userId,
        public string $newEmail,
        #[SensitiveParameter]
        public string $currentPassword,
    ) {
    }
}
