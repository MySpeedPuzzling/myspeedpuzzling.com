<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeletePuzzlingTeam
{
    public function __construct(
        public string $teamId,
        public string $playerId,
    ) {
    }
}
