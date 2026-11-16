<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;

/**
 * The outcome of CheckResultsSource: the sheet as it was read, or why it could not be.
 */
readonly final class RecordResultsImportCheck
{
    public function __construct(
        public string $checkId,
        public string $sourceId,
        public string $checkedByPlayerId,
        public DateTimeImmutable $checkedAt,
        public null|SheetSnapshot $snapshot,
        public null|string $readError,
    ) {
    }
}
