<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\OrganizationItemKind;

/**
 * Moves a series or a one-time event into an organization, or out of it (null) - docs/features/organizations/
 * README.md "Permissions". The caller checks the actor may edit the item (voters / internal API); the handler checks
 * the actor is on the target organization's team (or an admin) and approves a pending item moved under a trusted
 * organization (OrganizationApprovalPolicy).
 */
readonly final class AssignEventToOrganization
{
    public function __construct(
        public OrganizationItemKind $kind,
        public string $itemId,
        public null|string $organizationId,
        public string $actingPlayerId,
    ) {
    }
}
