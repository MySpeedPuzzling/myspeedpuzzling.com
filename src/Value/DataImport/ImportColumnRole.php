<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What a column of a known export is beyond the "This is" list (ImportField): the things only an app's own export
 * carries. Stored with a sheet's mapping - never rename one.
 */
enum ImportColumnRole: string
{
    /** The source's own id of the row - the row's identity within that source (README "Keys, exactly") */
    case SourceRowId = 'source_row_id';
    /** Our own export: the catalogue puzzle's id */
    case PuzzleId = 'puzzle_id';
    /** A link to a picture of the puzzle, kept with the row */
    case PictureUrl = 'picture_url';
}
