<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * The end of a monthly snapshot run (sent by SnapshotPlayerInsightsHandler): the month's rows of every player the run
 * did not write go - a re-run replaces the month instead of keeping rows of players no longer active.
 */
readonly final class PrunePlayerInsightsSnapshots
{
    /**
     * @param string $month Y-m-d, the first day of the month
     * @param list<string> $keptPlayerIds the players the run wrote
     */
    public function __construct(
        public string $month,
        public array $keptPlayerIds,
    ) {
    }
}
