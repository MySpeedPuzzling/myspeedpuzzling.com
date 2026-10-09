<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Approves a pending organization and its pending series and one-time events (docs/features/organizations/README.md,
 * P2).
 */
readonly final class ApproveOrganization
{
    public function __construct(
        public string $organizationId,
        public string $approvedByPlayerId,
        // The "approved" e-mail to its creator - false when the creator is the one approving (internal API)
        public bool $notifyCreator = true,
    ) {
    }
}
