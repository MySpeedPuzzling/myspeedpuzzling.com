<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One event of an organizer's results source, read and checked by the source's format - the neutral model every format
 * gives the shared import (docs/features/event-results-import/results-import-page.md "Three layers"). Whether the
 * linked puzzle exists is for the import to find out - here a link is only a well-formed one.
 */
readonly final class ParsedEvent
{
    /**
     * @param list<ParsedEventRow> $rows
     * @param list<EventTabProblem> $problems
     */
    public function __construct(
        // The event's identity across reads, chosen by the format (Sarah Does Puzzles: the tab's gid, which survives a
        // rename of the tab) - a re-import matches by it. At most 20 characters (event_results_sheet_tab.gid)
        public string $key,
        // What the organizer calls it in their sheet (the tab's name) - shown with its problems
        public string $title,
        public null|string $eventName,
        public null|string $location,
        public null|string $cityAndState,
        // The start as written - the wall clock of the event's place, the import puts it into its time zone
        // Only the day given (no "Start time"): $localStartsAt is noon of it
        public null|DateTimeImmutable $localStartsAt,
        public bool $startTimeKnown,
        // null = no "Time limit" given
        public null|int $timeLimitMinutes,
        public null|RoundCategory $category,
        public null|string $puzzleId,
        public array $rows,
        public array $problems,
        // Changes only when something the import reads changes - an unchanged event is not applied again. The format's
        // own: never of the key or the title, and never recomputed another way (stored hashes would all differ)
        public string $contentHash,
        // A relay (solo-and-relays.md §2): the second puzzle every team solved with the first, ranked by the total time
        public null|string $secondPuzzleId = null,
    ) {
    }

    public function isRelay(): bool
    {
        return $this->secondPuzzleId !== null;
    }

    /**
     * The event's puzzles in the sheet's order - one, or a relay's two.
     *
     * @return list<string>
     */
    public function puzzleIds(): array
    {
        return array_values(array_filter([$this->puzzleId, $this->secondPuzzleId], static fn (null|string $id): bool => $id !== null));
    }

    public function hasTabProblem(): bool
    {
        foreach ($this->problems as $problem) {
            if ($problem->code->keepsTheWholeTabOut()) {
                return true;
            }
        }

        return false;
    }

    public function waitsForPuzzle(): bool
    {
        return $this->hasTabProblem() === false && $this->puzzleId === null;
    }

    public function isImportable(): bool
    {
        return $this->hasTabProblem() === false && $this->puzzleId !== null;
    }
}
