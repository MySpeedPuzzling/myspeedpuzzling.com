<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class ChangeAccountPassword
{
    public function __construct(
        public string $userId,
        #[SensitiveParameter]
        public string $currentPassword,
        #[SensitiveParameter]
        public string $newPassword,
    ) {
    }
}
