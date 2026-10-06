<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

readonly final class ApiUsageTotals
{
    public function __construct(
        public int $requests,
        // 4xx, 429 and 5xx
        public int $failed,
        public int $tooManyRequests,
        public int $durationMsTotal,
        // Busiest minute of a single caller in the period (null when filtered by request type)
        public null|int $peakRequestsPerMinute,
        public null|DateTimeImmutable $lastRequestAt,
    ) {
    }

    public function averageMs(): null|int
    {
        return $this->requests > 0 ? intdiv($this->durationMsTotal, $this->requests) : null;
    }

    public function failedPercent(): null|float
    {
        return $this->requests > 0 ? round($this->failed / $this->requests * 100, 1) : null;
    }
}
