<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\MarketplaceEventsBanner;

/**
 * What the "Marketplace at events" banner needs to know about a player (GetMarketplaceEventsHintState).
 * `nearestEvent` is the nearest marketplace event they go to, the two counts are about that event:
 * how many of their published listings they mark for it, how many other going sellers' listings are coming.
 */
readonly final class MarketplaceEventsHintState
{
    public function __construct(
        public bool $hasPublishedListing,
        public null|MarketplaceEvent $nearestEvent,
        public int $markedForNearestEvent,
        public int $broughtByOthers,
    ) {
    }

    /**
     * Only members can mark puzzles for an event, so an expired member's published listings do not make a seller.
     */
    public function banner(bool $activeMembership): null|MarketplaceEventsBanner
    {
        $sells = $activeMembership && $this->hasPublishedListing;

        if ($sells && $this->nearestEvent === null) {
            return MarketplaceEventsBanner::Seller;
        }

        if ($sells && $this->markedForNearestEvent === 0) {
            return MarketplaceEventsBanner::Going;
        }

        if ($sells === false && $this->nearestEvent !== null && $this->broughtByOthers > 0) {
            return MarketplaceEventsBanner::Buyer;
        }

        return null;
    }
}
