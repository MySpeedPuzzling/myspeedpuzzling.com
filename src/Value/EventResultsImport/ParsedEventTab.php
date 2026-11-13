<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

/**
 * One tab of an organizer's results sheet, read and checked (docs/features/event-results-import/README.md "Format").
 * Whether the linked puzzle exists is for the import to find out - here a link is only a well-formed one.
 */
readonly final class ParsedEventTab
{
    /**
     * @param list<ParsedEventRow> $rows
     * @param list<EventTabProblem> $problems
     */
    public function __construct(
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
        // Changes only when something the import reads changes - an unchanged tab is not applied again
        public string $contentHash,
    ) {
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
