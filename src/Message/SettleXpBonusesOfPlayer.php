<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The ex-post bonus settlement of one player (SettleXpBonuses dispatches it for each player with something to be paid).
 * Not routed: handled at once, in a transaction of its own - the player's lock is held only for that transaction.
 */
readonly final class SettleXpBonusesOfPlayer
{
    public function __construct(
        public string $playerId,
    ) {
    }
}
