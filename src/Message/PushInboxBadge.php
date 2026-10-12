<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Push the player's Inbox badge to their open pages (docs/features/navigation INB-02) - dispatched with
 * DispatchAfterCurrentBusStamp by handlers that change the badge's numbers through entities (a request accepted or
 * ignored, a block, an unblock): the numbers are read after their transaction committed, never before the flush.
 */
readonly final class PushInboxBadge
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
