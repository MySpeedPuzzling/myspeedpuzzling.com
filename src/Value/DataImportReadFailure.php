<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetUnreadableReason;

/**
 * Why an import's file was not read - what `data_import.read_error` holds (docs/features/data-import/reading.md
 * "When reading fails"): a SpreadsheetUnreadableReason's value when it is about the file, or one of the two below
 * when it is not. The page has a sentence per reason (`data_import.read_failed.<reason>`).
 */
final class DataImportReadFailure
{
    /** A fault of the server: storage, the disk, a bug. Logged as an error; "something went wrong on our side" */
    public const string OUR_SIDE = 'failed';
    /** The worker died while reading (ReadWaitingImportsHandler). Shown like OUR_SIDE */
    public const string STALLED = 'stalled';

    /**
     * The reason a page has a sentence for - anything unknown is ours.
     */
    public static function shown(null|string $readError): string
    {
        return $readError !== null && SpreadsheetUnreadableReason::tryFrom($readError) !== null ? $readError : self::OUR_SIDE;
    }
}
