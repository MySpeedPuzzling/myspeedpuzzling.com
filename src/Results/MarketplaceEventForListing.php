<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A marketplace event the seller goes to, seen from one of their listings (GetMarketplaceEvents::forListingSeller()):
 * whether the listing is marked for it, and - in a conversation about the listing - whether the other player goes too.
 */
readonly final class MarketplaceEventForListing
{
    public function __construct(
        public MarketplaceEvent $event,
        public bool $bringing,
        public bool $otherPlayerGoing,
    ) {
    }
}
