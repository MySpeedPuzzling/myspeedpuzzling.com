<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SpreadsheetReader\Xlsx;

/**
 * How many more bytes of XML an opened workbook may unpack and parse - one budget for every pass over every part
 * (SpreadsheetLimits::$maxReadBytes). Reading the same part a hundred times costs a hundred times its size.
 */
final class ReadBudget
{
    public function __construct(
        public int $remaining,
        public int $read = 0,
    ) {
    }
}
