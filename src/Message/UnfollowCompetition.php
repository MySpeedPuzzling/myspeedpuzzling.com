<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\SerializedByLock;

/**
 * docs/features/events-page/README.md, "Follow". The follows of one player take turns - a double tap on a star never
 * runs a follow and an unfollow of the same target at the same moment.
 */
readonly final class UnfollowCompetition implements SerializedByLock
{
    public function __construct(
        public string $playerId,
        // FollowTarget::toString() - "competition:<uuid>" or "series:<uuid>"
        public string $target,
    ) {
    }

    public function lockKey(): string
    {
        return 'followed-competitions-' . strtolower($this->playerId);
    }
}
