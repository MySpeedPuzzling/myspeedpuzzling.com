<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RejectCompetition
{
    public function __construct(
        public string $competitionId,
        public string $rejectedByPlayerId,
        public string $reason,
    ) {
    }
}
