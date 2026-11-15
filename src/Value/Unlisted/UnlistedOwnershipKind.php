<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Unlisted;

/**
 * What a row of a spreadsheet says about owning an unlisted puzzle (docs/features/data-import/unlisted-puzzles.md).
 */
enum UnlistedOwnershipKind: string
{
    /** In the player's collection */
    case Owned = 'owned';
    /** On the player's wish list */
    case WishList = 'wish_list';
}
