<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a Google sheet was not fetched, as the player is told (docs/features/data-import/google-sheets.md "Outcomes") -
 * what `data_import.read_error` holds for an import in `fetch_failed`, next to DataImportReadFailure's two reasons
 * that are ours. The page has a sentence per reason (`data_import.fetch_failed.<reason>`).
 */
enum GoogleSheetFetchFailure: string
{
    /** Google asks to sign in: the sheet is not shared with "Anyone with the link" */
    case NotShared = 'not_shared';
    /** No such document - deleted, or the link was cut */
    case NotFound = 'not_found';
    /** The export is larger than an import takes */
    case TooLarge = 'too_large';
    /** Google did not hand the export over: its own size limit, a 5xx, a rate limit, no answer in time */
    case Refused = 'refused';
    /** What arrived is no .xlsx file */
    case NotASpreadsheet = 'not_a_spreadsheet';

    /**
     * The reason a page has a sentence for - anything unknown is ours.
     */
    public static function shown(null|string $readError): string
    {
        return $readError !== null && self::tryFrom($readError) !== null ? $readError : DataImportReadFailure::OUR_SIDE;
    }
}
