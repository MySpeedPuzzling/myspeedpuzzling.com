<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Deletes an empty organization - refused (OrganizationNotEmpty) while a series or an event is under it
 * (docs/features/organizations/README.md, P4). Its follows, team and redirect rows go with it.
 */
readonly final class DeleteOrganization
{
    public function __construct(
        public string $organizationId,
    ) {
    }
}
