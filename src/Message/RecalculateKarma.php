<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Entity\Player;

/**
 * Re-derive and store player.karma (docs/features/karma.md). Dispatched by every handler that decides something karma
 * counts - for the people that decision concerns - and by `myspeedpuzzling:recalculate-karma` for everybody.
 */
readonly final class RecalculateKarma
{
    /**
     * @param null|list<string> $playerIds Null = every player
     */
    public function __construct(
        public null|array $playerIds,
    ) {
    }

    /**
     * The people a decision concerns - missing ones (a deleted reporter, a puzzle without its adder) left out.
     */
    public static function forPlayers(null|Player ...$players): self
    {
        $ids = [];

        foreach ($players as $player) {
            if ($player !== null) {
                $ids[$player->id->toString()] = true;
            }
        }

        return new self(array_keys($ids));
    }
}
