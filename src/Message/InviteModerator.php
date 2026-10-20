<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class InviteModerator
{
    public function __construct(
        public UuidInterface $invitationId,
        public string $playerId,
        public null|string $invitedByPlayerId,
    ) {
    }
}
