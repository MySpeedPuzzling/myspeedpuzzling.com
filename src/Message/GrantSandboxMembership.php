<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * On the sandbox everybody is a member (docs/features/sandbox/README.md "Membership"): the player of this account gets a
 * granted membership unless they have a membership row already - one they had, or the seed's free test account's
 * ended one (that account stays free on purpose).
 */
readonly final class GrantSandboxMembership
{
    public function __construct(
        public string $userId,
    ) {
    }
}
