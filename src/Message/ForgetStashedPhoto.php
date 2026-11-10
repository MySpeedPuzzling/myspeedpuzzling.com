<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * The token stops working at once. Its objects in object storage are the caller's business (PhotoStash::discard()
 * deletes them, a replaced reservation leaves them to the daily prune).
 *
 * Fresh entity manager: dispatched on the refused-form path, where a handler rolled back just before may have left
 * entities behind - this message's flush must not write them.
 */
readonly final class ForgetStashedPhoto implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $token,
        public string $playerId,
    ) {
    }
}
