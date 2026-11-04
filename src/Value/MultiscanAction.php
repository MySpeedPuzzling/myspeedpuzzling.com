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
    case MoveToCollection = 'move_to_collection';
    case RemoveFromCollection = 'remove_from_collection';
    case AddToWishlist = 'add_to_wishlist';
    case Lend = 'lend';
    case Borrow = 'borrow';
    case Return = 'return';

    public function needsPerson(): bool
    {
        return $this === self::Lend || $this === self::Borrow;
    }

    /** The collection the puzzles go into (picker with "create a new one") */
    public function needsTargetCollection(): bool
    {
        return $this === self::AddToLibrary || $this === self::MoveToCollection;
    }

    /** The collection the puzzles leave */
    public function needsSourceCollection(): bool
    {
        return $this === self::MoveToCollection || $this === self::RemoveFromCollection;
    }

    public function isCollectionAction(): bool
    {
        return $this->needsTargetCollection() || $this->needsSourceCollection();
    }
}
