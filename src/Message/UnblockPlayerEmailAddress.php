<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Lifts a block by hand from the player's current address. Changing the address frees the player without this.
 */
readonly final class UnblockPlayerEmailAddress
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
