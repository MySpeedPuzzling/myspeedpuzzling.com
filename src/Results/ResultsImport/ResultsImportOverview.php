<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\EventResultsImport\UnreadableSheetTab;

/**
 * A check of a results source compared with what is stored - the list of the results import page.
 */
readonly final class ResultsImportOverview
{
    /**
     * @param list<ResultsImportEvent> $events in the sheet's order
     * @param list<UnreadableSheetTab> $unreadableTabs tabs Google listed but did not give us
     */
    public function __construct(
        public string $checkId,
        public DateTimeImmutable $checkedAt,
        public null|string $checkedByName,
        // The sheet could not be read at all - no events
        public null|string $readError,
        public array $events,
        public array $unreadableTabs,
    ) {
    }

    public function event(string $key): null|ResultsImportEvent
    {
        foreach ($this->events as $event) {
            if ($event->event->key === $key) {
                return $event;
            }
        }

        return null;
    }

    /**
     * @return array<string, int> status value => count
     */
    public function statusCounts(): array
    {
        $counts = [];

        foreach ($this->events as $event) {
            $counts[$event->status->value] = ($counts[$event->status->value] ?? 0) + 1;
        }

        return $counts;
    }
}
