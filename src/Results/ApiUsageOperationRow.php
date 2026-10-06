<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One request type ("GET /api/v1/me/results") in a period
 */
readonly final class ApiUsageOperationRow
{
    public function __construct(
        public string $operation,
        public int $requests,
        public int $failed,
        public int $tooManyRequests,
        public int $durationMsTotal,
        public int $callers,
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
