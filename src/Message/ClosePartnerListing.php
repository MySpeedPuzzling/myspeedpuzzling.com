<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Value\PartnerListingClosedReason;

/**
 * "Found my partner" (reason found - a group still needing more than one person counts down instead) or "Remove listing"
 * (reason withdrawn), by the listing's owner only (docs/features/partner-finder.md D7). "Found" may name who they teamed
 * up with - each of them still listed at the event is asked to close theirs too (PartnerTeamedUp); anybody else is ignored.
 */
readonly final class ClosePartnerListing
{
    public function __construct(
        public string $listingId,
        public string $playerId,
        public PartnerListingClosedReason $reason,
        /** @var list<string> player ids */
        public array $teamedUpWith = [],
    ) {
    }
}
