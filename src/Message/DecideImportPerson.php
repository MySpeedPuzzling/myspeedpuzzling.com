<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;

/**
 * The importing player's word on one person their file names (docs/features/data-import/people.md "Answers"):
 * "it is this player" - one of the players offered (`targetPlayerId`) or a typed code (`code`) -, "not on
 * MySpeedPuzzling - keep as a guest", or "that is me". Remembered for the player's next file
 * (import_person_decision). It decides the rows that are added from now on; results that are added already are
 * never rewritten.
 */
readonly final class DecideImportPerson
{
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $personId,
        public ImportPersonChoice $choice,
        public null|string $targetPlayerId = null,
        public null|string $code = null,
    ) {
    }
}
