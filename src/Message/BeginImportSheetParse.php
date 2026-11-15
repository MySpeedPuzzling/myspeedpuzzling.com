<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * A parse of a sheet for a mapping version wants to start: under the import's lock it is claimed - once
 * (DataImportSheet::claimParse()). Answers whether this job is the one that parses: not when the sheet is already
 * parsed for that version (a second delivery, the safety net), when another job's claim is fresh, when the mapping
 * moved on, or when the sheet was given up after too many starts.
 */
readonly final class BeginImportSheetParse implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public string $sheetId,
        public int $mappingVersion,
    ) {
    }
}
