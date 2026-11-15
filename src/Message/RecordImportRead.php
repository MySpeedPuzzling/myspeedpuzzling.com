<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Every sheet of the file was read: `reading` → `setting_up`.
 */
readonly final class RecordImportRead
{
    public function __construct(
        public string $importId,
        public int $readAttempt,
    ) {
    }
}
