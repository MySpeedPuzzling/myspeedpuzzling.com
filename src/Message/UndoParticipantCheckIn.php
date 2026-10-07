<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UndoParticipantCheckIn
{
    public function __construct(
        public string $participantId,
    ) {
    }
}
