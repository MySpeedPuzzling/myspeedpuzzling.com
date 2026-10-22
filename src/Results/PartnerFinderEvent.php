<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * An event with a partner finder (PartnerFinderEligibility::SQL_QUALIFIES) and how many open listings it has - a row of
 * the partner finder gate page (docs/features/partner-finder.md "Part D as built").
 */
readonly final class PartnerFinderEvent
{
    public function __construct(
        public MarketplaceEvent $event,
        public int $listingCount,
    ) {
    }
}
