<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What FlagWithDateReader made of a cell that may hold two facts - "Y - 30/12/2023": a yes/no and a date.
 */
final readonly class ReadFlagWithDate
{
    public function __construct(
        public ReadYesNo $flag,
        public ReadDate $date,
        /** What stands next to a known flag and is no date ("Yes, twice") */
        public null|string $leftover = null,
    ) {
    }
}
