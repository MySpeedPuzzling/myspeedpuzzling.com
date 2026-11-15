<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * How DataRegionFinder found a table on a sheet. Stored with a sheet's mapping - never rename one.
 */
enum DataRegionSource: string
{
    /** The file says so: Excel's "Format as table" */
    case Table = 'table';
    /** The row above a frozen pane */
    case FrozenPane = 'frozen_pane';
    /** The first row whose cells are column names we know */
    case HeaderWords = 'header_words';
    /** The first row of texts over rows of other types */
    case Types = 'types';
    /** No header row was found: the columns are offered as "Column N" */
    case NoHeader = 'no_header';
    /** The player moved it */
    case Player = 'player';
}
