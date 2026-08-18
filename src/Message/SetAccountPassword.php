<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

/**
 * Sets a password without asking for the previous one. Only dispatch it after
 * the user has proven ownership some other way (magic sign-in link, password
 * reset token) - the caller owns that proof, the handler cannot check it.
 */
final readonly class SetAccountPassword
{
    public function __construct(
        public string $userId,
        #[SensitiveParameter]
        public string $plainPassword,
    ) {
    }
}
