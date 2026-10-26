<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\HandledOutsideTransaction;

/**
 * Periodic (15-min cron, after puzzle-intelligence recalc) ex-post settlement of pending
 * difficulty and speed bonuses for go-forward solves.
 *
 * Outside a transaction (HandledOutsideTransaction): the handler only finds who gets something paid and settles each
 * player by SettleXpBonusesOfPlayer - not routed, so handled at once, in a transaction of its own. One transaction for
 * the whole run held every player it locked for its whole length (~15 s, 2026-10-26).
 */
readonly final class SettleXpBonuses implements HandledOutsideTransaction
{
}
