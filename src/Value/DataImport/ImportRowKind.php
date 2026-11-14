<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a row of an imported sheet is (docs/features/data-import/README.md "What a row is"). The values are part of a
 * row's `source_key` - never rename one.
 */
enum ImportRowKind: string
{
    case Result = 'result';
    case Owned = 'owned';
    /** A result of a puzzle the player owns */
    case Both = 'both';
    case WishList = 'wish_list';
}
