<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * How many listings match the marketplace's filters. Under the event filter the two parts of the one result list:
 * `bringing` (marked for the event) and `toAsk` (the going sellers' other published listings) - both counted even
 * when "Only what they're bringing" hides the second part, `total` is what the list shows.
 */
readonly final class MarketplaceListingsCount
{
    public function __construct(
        public int $total,
        public int $bringing = 0,
        public int $toAsk = 0,
    ) {
    }
}
