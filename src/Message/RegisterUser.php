<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

readonly final class RegisterUser
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public string $plainPassword,
        public null|string $locale,
    ) {
    }
}
