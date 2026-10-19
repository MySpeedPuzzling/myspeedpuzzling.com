<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * One chunk of monthly Insights snapshots to store (sent by SnapshotPlayerInsightsHandler).
 */
readonly final class StorePlayerInsightsSnapshots
{
    /**
     * @param string $month Y-m-d, the first day of the month
     * @param array<string, array<string, mixed>> $snapshots player id => snapshot data
     */
    public function __construct(
        public string $month,
        public array $snapshots,
    ) {
    }
}
