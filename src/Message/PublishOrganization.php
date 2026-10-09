<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The organization's draft flag goes off - one still waiting for approval enters the approval queue then
 * (docs/features/organizations/README.md "Drafts").
 */
readonly final class PublishOrganization
{
    public function __construct(
        public string $organizationId,
        // The admins get the "submitted" e-mail when a pending item is published - never when an admin publishes it
        public bool $notifyAdmin = true,
    ) {
    }
}
