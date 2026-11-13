<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SensitiveParameter;

/**
 * Creates the sandbox's made-up players `test-user+1..N@myspeedpuzzling.com` and the free account
 * `test-user+free@myspeedpuzzling.com` that are missing (docs/features/sandbox/README.md "Data"). Sandbox only.
 * Everybody signs in with the same published password - it is a sandbox, there is nothing to protect in it.
 */
readonly final class CreateSandboxPlayers
{
    public function __construct(
        public int $count,
        #[SensitiveParameter]
        public string $password,
    ) {
    }
}
