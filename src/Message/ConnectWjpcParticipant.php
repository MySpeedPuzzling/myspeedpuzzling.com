<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class ConnectWjpcParticipant
{
    public function __construct(
        public string $playerId,
        public null|string $participantId,
    ) {
    }
}
