<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * Rows and columns a sheet keeps in view while it scrolls. Frozen rows 1-6 = firstUnfrozenRow 7; nothing frozen in a
 * direction = 1.
 */
final readonly class FrozenPane
{
    public function __construct(
        public int $firstUnfrozenRow,
        public int $firstUnfrozenColumn,
    ) {
    }
}
