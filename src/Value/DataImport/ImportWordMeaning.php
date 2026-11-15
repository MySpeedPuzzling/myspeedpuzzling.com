<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a word or tag of a column means (docs/features/data-import/README.md "Words that mean something") - one
 * answer per distinct value, stored in `data_import_sheet.value_answers`. Never rename a value.
 */
enum ImportWordMeaning: string
{
    // What a row is - the words of a status column
    case Result = 'result';
    case Owned = 'owned';
    case WishList = 'wish_list';
    /** Solved and owned */
    case Both = 'both';
    // Who solved it
    case Solo = 'solo';
    case Pair = 'pair';
    case Team = 'team';
    case FirstTry = 'first_try';
    /** Sold, returned, given away */
    case NotMine = 'not_mine';
    case Borrowed = 'borrowed';
    /** The word says nothing the import uses */
    case Nothing = 'nothing';
}
