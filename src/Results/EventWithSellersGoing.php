<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A marketplace event at least one seller with published listings is going to - an option of the marketplace's
 * "Pick up at an event" select. Counts are aggregates over all going sellers' published listings (nobody's blocks
 * apply): `bringingCount` marked for the event, `askCount` the rest the sellers can be asked to bring.
 */
readonly final class EventWithSellersGoing
{
    public function __construct(
        public MarketplaceEvent $event,
        public int $bringingCount,
        public int $askCount,
    ) {
    }
}
