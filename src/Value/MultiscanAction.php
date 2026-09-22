<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The batch actions of the multiscan tray (docs/features/multiscan/README.md §4).
 * The value doubles as the `?action=` URL slug of the contextual entry points.
 */
enum MultiscanAction: string
{
    case AddToLibrary = 'add_to_library';
    case AddToWishlist = 'add_to_wishlist';
    case Lend = 'lend';
    case Borrow = 'borrow';
    case Return = 'return';

    public function needsPerson(): bool
    {
        return $this === self::Lend || $this === self::Borrow;
    }
}
