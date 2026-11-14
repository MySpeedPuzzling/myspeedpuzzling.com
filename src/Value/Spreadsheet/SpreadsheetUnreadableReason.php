<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * Why SpreadsheetReader refused a file (docs/features/data-import/reader.md "Limits and reasons").
 */
enum SpreadsheetUnreadableReason: string
{
    /** The file, or what it unpacks to, is over a size limit (a zip bomb included) */
    case TooLarge = 'too_large';
    case TooManySheets = 'too_many_sheets';
    case TooManyRows = 'too_many_rows';
    case TooManyColumns = 'too_many_columns';
    case TooManyCells = 'too_many_cells';
    /** Not tabular data at all: a picture, a text document, a zip of something else */
    case NotASpreadsheet = 'not_a_spreadsheet';
    /** A workbook or text file that is broken: truncated, invalid XML, parts missing or lying about themselves */
    case Damaged = 'damaged';
    case PasswordProtected = 'password_protected';
    /** A spreadsheet in a format that is not read: .xls, .xlsb, .ods, .numbers, or content other than the format asked for */
    case UnsupportedFormat = 'unsupported_format';
}
