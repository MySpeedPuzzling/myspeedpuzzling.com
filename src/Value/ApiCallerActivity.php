<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * One caller on one day: the busiest minute and the latest request.
 */
final readonly class ApiCallerActivity
{
    public function __construct(
        public ApiCaller $caller,
        public int $peakRequestsPerMinute,
        public DateTimeImmutable $lastRequestAt,
    ) {
    }
}
