<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "These are not mine - take me off them" (docs/features/data-import/people.md "A partner can get out"): the player
 * takes themself off every result the importing player's spreadsheet imports put them on, and no import of that
 * importing player resolves them from now on. Answers the number of results they were taken off.
 */
readonly final class LeaveImportedResults
{
    public function __construct(
        public string $playerId,
        public string $importerId,
    ) {
    }
}
