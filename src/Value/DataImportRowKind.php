<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What one row of an import is (docs/features/data-import/README.md "Data model").
 */
enum DataImportRowKind: string
{
    case Result = 'result';
    case Owned = 'owned';
    case Both = 'both';
    /** A puzzle on the wish list */
    case WishList = 'wish_list';
    case NotAPuzzle = 'not_a_puzzle';
}
