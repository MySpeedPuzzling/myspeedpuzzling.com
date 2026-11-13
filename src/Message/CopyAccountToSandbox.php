<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

/**
 * A real person's account copied from production into the sandbox (docs/features/sandbox/README.md "Copying a real
 * account"): they sign in there with the same e-mail and password. Only the sign-in and the basic profile - nothing
 * of their results, collections or pictures. Sandbox only.
 */
readonly final class CopyAccountToSandbox
{
    public function __construct(
        public string $email,
        #[SensitiveParameter]
        public null|string $passwordHash,
        public bool $emailVerified,
        public null|string $name,
        public string $code,
        public null|string $country,
        public null|string $locale,
        public null|string $timezone,
    ) {
    }
}
