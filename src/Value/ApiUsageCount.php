<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Requests of one caller, of one request type, ending in one status class, on one day.
 */
final readonly class ApiUsageCount
{
    public function __construct(
        public ApiCaller $caller,
        public string $operation,
        public ApiStatusClass $statusClass,
        public int $requests,
        public int $durationMsTotal,
    ) {
    }
}
