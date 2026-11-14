<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\Spreadsheet;

/**
 * A number format code, classified (NumberFormatClassifier).
 */
final readonly class NumberFormat
{
    public function __construct(
        public NumberFormatKind $kind,
        /** Dates only: the smallest unit the format shows */
        public null|DatePrecision $precision = null,
        /** Dates only: false for "m/d", "d.m." - the year is in the value but the player never saw it */
        public bool $yearShown = false,
        /** Dates only: the format shows a time next to the date */
        public bool $timeShown = false,
        /** Numbers only: shown as a percentage */
        public bool $percent = false,
    ) {
    }

    public static function general(): self
    {
        return new self(NumberFormatKind::General);
    }
}
