<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * One read of a results source: what was fetched and the events its format made of it.
 */
readonly final class ResultsSourceRead
{
    /**
     * @param list<ParsedEvent> $events
     */
    public function __construct(
        public SheetSnapshot $snapshot,
        public array $events,
    ) {
    }
}
