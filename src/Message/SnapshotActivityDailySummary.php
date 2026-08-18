<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class SnapshotActivityDailySummary
{
    /**
     * @param null|string $day UTC day to snapshot as Y-m-d; null = yesterday (UTC)
     */
    public function __construct(
        public null|string $day = null,
    ) {
    }
}
