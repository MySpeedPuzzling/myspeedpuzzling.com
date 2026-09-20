<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Fixes how a guest (a co-puzzler without an account) is spelled - in every pair/team the player shares
 * with them and in the group snapshots of those results.
 */
readonly final class RenameTeamGuest
{
    public function __construct(
        public string $playerId,
        // Member key of the guest, "g:" + normalised name - see TeamComposition
        public string $guestKey,
        public string $newName,
    ) {
    }
}
