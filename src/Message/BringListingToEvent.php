<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Bring to event" from a conversation: marks one listing as brought to the event (idempotent) and, with
 * `reserveForPlayerId`, reserves it for that buyer as well.
 */
readonly final class BringListingToEvent
{
    public function __construct(
        public string $playerId,
        public string $listItemId,
        public string $competitionId,
        public null|string $reserveForPlayerId,
    ) {
    }
}
