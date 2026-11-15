<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use SpeedPuzzling\Web\Services\MessengerMiddleware\RequiresFreshEntityManagerState;

/**
 * Every row of a sheet was parsed for a mapping version: the sheet's counts are stored and its equal rows are
 * numbered and linked (ImportEqualRows, then across the import's sheets). Refused like a batch when the version is
 * not the sheet's current one. Answers whether it was stored.
 */
readonly final class RecordImportSheetParsed implements RequiresFreshEntityManagerState
{
    public function __construct(
        public string $importId,
        public string $sheetId,
        public int $mappingVersion,
        public int $rowsUnderstood,
        public int $rowsNeedLook,
        public int $rowsLeftOut,
    ) {
    }
}
