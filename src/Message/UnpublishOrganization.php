<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Back to draft - always allowed: a draft organization hides only its own page, directory entry and "Organized by"
 * links, never its series or events (docs/features/organizations/README.md "Drafts").
 */
readonly final class UnpublishOrganization
{
    public function __construct(
        public string $organizationId,
    ) {
    }
}
