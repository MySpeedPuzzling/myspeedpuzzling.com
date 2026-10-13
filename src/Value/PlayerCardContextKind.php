<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a player card was opened from (docs/features/navigation CARD-02): the page's subject the card can relate the
 * player to. Its value is the `card/<kind>/…` part of the card's data-nav ids (P0-03).
 */
enum PlayerCardContextKind: string
{
    case Puzzle = 'puzzle';
    case Feed = 'feed';
    case Notification = 'notification';
    case Round = 'round';
    case Leaderboard = 'leaderboard';
}
