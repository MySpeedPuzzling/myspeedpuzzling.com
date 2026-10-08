<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RemoveOrganizationMaintainer
{
    public function __construct(
        public string $organizationId,
        public string $playerId,
    ) {
    }
}
