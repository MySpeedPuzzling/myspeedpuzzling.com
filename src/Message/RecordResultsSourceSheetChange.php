<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;

/**
 * The outcome of ChangeResultsSourceSheet once the new spreadsheet was read: the source reads it from now on, and the
 * read is its check (who changed it and when).
 */
readonly final class RecordResultsSourceSheetChange
{
    public function __construct(
        public string $checkId,
        public string $sourceId,
        public string $spreadsheetId,
        public string $changedByPlayerId,
        public DateTimeImmutable $readAt,
        public SheetSnapshot $snapshot,
    ) {
    }
}
