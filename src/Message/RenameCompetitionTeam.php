<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RenameCompetitionTeam
{
    public function __construct(
        public string $teamId,
        /** Free text - whitespace is tidied up, empty leaves the team without a name */
        public null|string $name,
    ) {
    }
}
