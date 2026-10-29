<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Nothing at all is sent to the player's current address any more, and the player reads $reason in a banner asking
 * them to check and change the address (docs/features/mass-mailing/README.md "Blocked by hand").
 */
readonly final class BlockPlayerEmailAddress
{
    public const int REASON_MAX_LENGTH = 500;

    public function __construct(
        public string $playerId,
        public string $reason,
    ) {
    }
}
