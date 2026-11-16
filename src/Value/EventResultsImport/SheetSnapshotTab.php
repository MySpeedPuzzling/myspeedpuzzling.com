<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

readonly final class SheetSnapshotTab
{
    public function __construct(
        // Google's id of the tab - kept when the tab is renamed or moved
        public string $gid,
        public string $title,
        // The tab as Google's CSV export gives it
        public string $csv,
    ) {
    }
}
