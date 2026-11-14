<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * One possible reading of an ambiguous time value: the rule and the seconds it gives.
 */
final readonly class DurationReading
{
    public function __construct(
        public DurationReadingRule $rule,
        public int $seconds,
    ) {
    }
}
