<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\ApiUsage;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\ApiRequestRecord;
use SpeedPuzzling\Web\Value\ApiUsageSnapshot;

/**
 * The request-time side of the API usage statistics (docs/features/api/usage-statistics.md):
 * record() runs once per API request after the response is sent, snapshot()
 * is read by the cron that copies the totals into Postgres.
 */
interface ApiUsageCounter
{
    public function record(ApiRequestRecord $record): void;

    /**
     * @param DateTimeImmutable $day any moment of the UTC day to read
     */
    public function snapshot(DateTimeImmutable $day): ApiUsageSnapshot;
}
