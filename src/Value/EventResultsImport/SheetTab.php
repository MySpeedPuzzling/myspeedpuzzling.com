<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

readonly final class SheetTab
{
    public function __construct(
        // Google's id of the tab - kept when the tab is renamed or moved, so it is the tab's identity
        public string $gid,
        public string $title,
    ) {
    }
}
