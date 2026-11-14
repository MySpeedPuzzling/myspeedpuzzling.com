<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * One row of a time column for DurationColumnResolver: what was read, and the row's piece count when it is known.
 */
final readonly class DurationColumnCell
{
    public function __construct(
        public ReadDuration $read,
        public null|int $pieces = null,
    ) {
    }
}
