<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCompetitionTeam
{
    public function __construct(
        public string $teamId,
    ) {
    }
}
