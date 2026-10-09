<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RejectOrganization
{
    public function __construct(
        public string $organizationId,
        public string $rejectedByPlayerId,
        public string $reason,
    ) {
    }
}
