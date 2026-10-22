<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The "Still looking?" e-mail of one listing, sent async (RemindPartnerListings picks and marks the listings)
 */
readonly final class SendPartnerListingReminder
{
    public function __construct(
        public string $listingId,
    ) {
    }
}
