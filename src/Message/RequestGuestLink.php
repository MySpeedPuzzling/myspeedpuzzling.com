<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class RequestGuestLink
{
    public function __construct(
        public UuidInterface $requestId,
        public string $requesterPlayerId,
        public string $guestKey,
        // Player code of whoever the guest really is, with or without the leading #
        public string $targetPlayerCode,
    ) {
    }
}
