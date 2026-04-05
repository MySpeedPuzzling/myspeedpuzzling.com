<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RestoreCompetitionParticipant
{
    public function __construct(
        public string $participantId,
    ) {
    }
}
