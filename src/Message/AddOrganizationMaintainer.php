<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class AddOrganizationMaintainer
{
    public function __construct(
        public string $organizationId,
        public string $playerId,
    ) {
    }
}
