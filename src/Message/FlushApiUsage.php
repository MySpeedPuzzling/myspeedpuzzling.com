<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Copy the API usage counters (Redis) into the daily tables (Postgres) - every 5
 * minutes from cron (docs/features/api/usage-statistics.md).
 */
readonly final class FlushApiUsage
{
    public function __construct(
        // Today and the days before it: the counters keep three days, so a cron
        // outage of up to two days loses nothing
        public int $days = 3,
    ) {
    }
}
