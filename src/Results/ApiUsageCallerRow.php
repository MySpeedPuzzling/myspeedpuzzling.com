<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ApiCaller;

/**
 * One API caller in a period, with what is needed to name it (admin overview)
 */
readonly final class ApiUsageCallerRow
{
    public function __construct(
        public ApiCaller $caller,
        public null|string $playerName,
        public null|string $playerCode,
        public null|string $tokenName,
        public null|string $clientName,
        public int $requests,
        public int $failed,
        public int $tooManyRequests,
        public int $durationMsTotal,
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
