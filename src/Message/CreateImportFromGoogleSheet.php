<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A player brings a spreadsheet as a Google Sheets link (docs/features/data-import/google-sheets.md): the import is
 * created at once in the state `fetching` - the player lands on its page - and StartImportFromGoogleSheet is queued
 * for the worker in the same transaction. Only the document's id travels, never the pasted link.
 */
readonly final class CreateImportFromGoogleSheet
{
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $spreadsheetId,
    ) {
    }
}
