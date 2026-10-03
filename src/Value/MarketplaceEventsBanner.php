<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Which "Marketplace at events" banner a player gets (HintType::MarketplaceAtEvents,
 * docs/features/marketplace/11-events.md) - MarketplaceEventsHintState::banner() decides.
 */
enum MarketplaceEventsBanner: string
{
    /** Sells, goes to no marketplace event → upcoming events */
    case Seller = 'seller';

    /** Sells, goes to one, marked nothing for the nearest → the picker */
    case Going = 'going';

    /** Does not sell, goes to one others are bringing puzzles to → the marketplace with that event */
    case Buyer = 'buyer';
}
