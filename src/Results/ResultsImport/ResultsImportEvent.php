<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use SpeedPuzzling\Web\Value\EventResultsImport\ParsedEvent;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportStatus;

/**
 * One event of a check on the results import page: what the sheet says, its status against what is stored, why it
 * waits or needs fixing, the soft warnings and what importing it would change.
 */
readonly final class ResultsImportEvent
{
    /**
     * @param list<ResultsImportNote> $blockers why it waits or needs fixing - nothing of it is imported
     * @param list<ResultsImportNote> $warnings never block: skipped rows and soft warnings
     * @param list<EventFieldChange> $eventChanges
     * @param list<TeamChange> $teamChanges
     */
    public function __construct(
        public ParsedEvent $event,
        public ResultsImportStatus $status,
        public null|StoredResultsTab $stored,
        public null|ResultsImportPuzzle $puzzle,
        public array $blockers,
        public array $warnings,
        public array $eventChanges,
        public array $teamChanges,
    ) {
    }

    public function teamCount(): int
    {
        return count($this->event->rows);
    }

    /**
     * Teams the import adds, changes or removes - not the ones it keeps because they are claimed.
     */
    public function changedTeamCount(): int
    {
        return count(array_filter($this->teamChanges, static fn (TeamChange $change): bool => $change->kind !== TeamChange::KEPT_CLAIMED));
    }
}
