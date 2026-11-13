<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class RecordEventResultsSheetFetch
{
    public function __construct(
        public string $sheetId,
        // Why the sheet could not be read, null = it was read
        public null|string $error,
    ) {
    }
}
