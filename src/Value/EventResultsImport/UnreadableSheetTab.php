<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

readonly final class UnreadableSheetTab
{
    public function __construct(
        public string $gid,
        public string $title,
        // Why Google did not give it to us
        public string $reason,
    ) {
    }
}
