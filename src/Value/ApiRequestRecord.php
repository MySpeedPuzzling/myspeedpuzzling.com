<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * One public API request as the usage counters see it.
 */
final readonly class ApiRequestRecord
{
    public function __construct(
        public ApiCaller $caller,
        public string $operation,
        public ApiStatusClass $statusClass,
        public int $durationMs,
        public DateTimeImmutable $at,
    ) {
    }
}
