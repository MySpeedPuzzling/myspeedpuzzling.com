<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A player who switched the newsletter off turns it back on with one click in the footer.
 */
readonly final class TurnOnNewsletter
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
