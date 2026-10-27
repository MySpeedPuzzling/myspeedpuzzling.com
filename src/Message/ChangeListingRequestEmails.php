<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The "Sell/swap request e-mails" switch (`player.listing_request_emails`, docs/features/marketplace/12-listing-requests.md):
 * the settings page and the e-mail's one-click unsubscribe.
 */
readonly final class ChangeListingRequestEmails
{
    public function __construct(
        public string $playerId,
        public bool $enabled,
    ) {
    }
}
