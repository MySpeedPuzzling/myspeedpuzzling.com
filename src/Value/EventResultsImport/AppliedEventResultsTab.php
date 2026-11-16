<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * What ApplyEventResultsTabHandler made of one event - its return value (the results import page logs it,
 * docs/features/event-results-import/results-import-page.md "As built: PR 3"). The sync ignores it.
 */
readonly final class AppliedEventResultsTab
{
    public function __construct(
        // The tab's status now; null when nothing was applied because a newer read was applied already (stale)
        public null|EventTabStatus $status,
        public null|string $competitionId,
        // Imported exactly like this before - nothing was done
        public bool $unchanged = false,
        // The event was applied after the read this one comes from (another check imported meanwhile) - refused
        public bool $stale = false,
        public int $teamsAdded = 0,
        public int $teamsUpdated = 0,
        public int $teamsRemoved = 0,
    ) {
    }

    public static function stale(null|string $competitionId): self
    {
        return new self(null, $competitionId, stale: true);
    }
}
