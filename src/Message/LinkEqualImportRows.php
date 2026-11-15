<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Links every row of an import to the row with the same key on an earlier sheet - the same result on two sheets is
 * one result (README D6) - and unlinks what no longer has a twin. Sent after a sheet was parsed
 * (RecordImportSheetParsed committed its keys); the whole import is compared, so any order of sheets ends the same.
 */
readonly final class LinkEqualImportRows
{
    public function __construct(
        public string $importId,
    ) {
    }
}
