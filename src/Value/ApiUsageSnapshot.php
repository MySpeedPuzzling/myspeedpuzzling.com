<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * Everything the counters hold for one UTC day - totals so far, never deltas.
 */
final readonly class ApiUsageSnapshot
{
    /**
     * @param list<ApiUsageCount> $counts
     * @param list<ApiCallerActivity> $callers
     */
    public function __construct(
        public DateTimeImmutable $day,
        public array $counts,
        public array $callers,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->counts === [] && $this->callers === [];
    }
}
