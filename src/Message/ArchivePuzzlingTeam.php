<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class ArchivePuzzlingTeam
{
    public function __construct(
        public string $teamId,
        public string $playerId,
        // false takes it out of the archive again
        public bool $archive = true,
    ) {
    }
}
