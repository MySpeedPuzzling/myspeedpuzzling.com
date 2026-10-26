<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Marketplace offers of one wishlist puzzle (GetWishlistMarketplaceAvailability): how many, and how many of their
 * sellers ship to the viewer's country (null = the viewer has no country, nothing to say).
 */
final readonly class WishlistPuzzleOffers
{
    public function __construct(
        public string $puzzleId,
        public int $offers,
        public null|int $shipsToViewer,
    ) {
    }
}
