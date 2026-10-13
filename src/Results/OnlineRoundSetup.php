<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Where a round's participants stand for online timing (GetOnlineRoundSetup): the event's participants who are going,
 * how many of them are entered in the round (only they compete in it) and how many have a personal link.
 */
readonly final class OnlineRoundSetup
{
    public function __construct(
        public int $going,
        public int $entered,
        public int $withLink,
    ) {
    }

    /**
     * Going, but not entered in the round yet - their link opens the competition, the round is not theirs
     */
    public function notEntered(): int
    {
        return max(0, $this->going - $this->entered);
    }
}
