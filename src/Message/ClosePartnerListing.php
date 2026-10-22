<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PartnerListingClosedReason;

/**
 * "Found my partner" (reason found - a group still needing more than one person counts down instead) or "Remove listing"
 * (reason withdrawn), by the listing's owner only (docs/features/partner-finder.md D7).
 */
readonly final class ClosePartnerListing
{
    public function __construct(
        public string $listingId,
        public string $playerId,
        public PartnerListingClosedReason $reason,
    ) {
    }
}
