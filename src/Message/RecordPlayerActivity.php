<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deliberately unrouted (sync): dispatched at kernel.terminate after the
 * response is sent, so the insert costs the user nothing either way. The
 * dispatching subscriber swallows failures - activity tracking must never
 * surface to the request.
 */
readonly final class RecordPlayerActivity
{
    public function __construct(
        public string $userId,
    ) {
    }
}
