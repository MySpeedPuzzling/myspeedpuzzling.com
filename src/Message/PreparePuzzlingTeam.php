<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Puts a pair/team together ahead of its first time, so it is one tap away in the add-time form.
 */
readonly final class PreparePuzzlingTeam
{
    public function __construct(
        public string $playerId,
        /** @var array<string> "#CODE" or a guest name, like the add-time form submits them */
        public array $groupPlayers,
        public null|string $name,
    ) {
    }
}
