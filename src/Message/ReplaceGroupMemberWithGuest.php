<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A registered player was added to somebody's pair/team result by mistake - the tracker meant a guest of the same name
 * (docs/features/pairs-and-teams/README.md, "A player added by mistake"). In this one result the player becomes the
 * guest; the group keeps its size. Dispatched by the internal API only.
 */
readonly final class ReplaceGroupMemberWithGuest
{
    public function __construct(
        public string $timeId,
        public string $playerId,
        // Null = the player's own name - who the tracker meant shares it
        public null|string $guestName = null,
    ) {
    }
}
