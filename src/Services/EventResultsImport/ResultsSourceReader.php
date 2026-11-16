<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventResultsImport;

use SpeedPuzzling\Web\Exceptions\GoogleSheetUnreadable;
use SpeedPuzzling\Web\Exceptions\UnknownResultsFormat;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsSourceRead;

/**
 * Reads a results source and lets its format make events of it (docs/features/event-results-import/
 * results-import-page.md "Three layers") - the one read of the sync and of the dry run. Writes nothing; takes seconds
 * (Google), so never inside a transaction.
 */
readonly final class ResultsSourceReader
{
    public function __construct(
        private GoogleSheetReader $reader,
        private ResultsFormats $formats,
    ) {
    }

    /**
     * @throws GoogleSheetUnreadable when the sheet's tabs cannot be listed
     * @throws UnknownResultsFormat
     */
    public function read(string $spreadsheetId, string $format): ResultsSourceRead
    {
        $resultsFormat = $this->formats->get($format);
        $snapshot = $this->reader->snapshot($spreadsheetId);

        return new ResultsSourceRead($snapshot, $resultsFormat->parse($snapshot));
    }
}
