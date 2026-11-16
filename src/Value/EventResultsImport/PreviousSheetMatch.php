<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventResultsImport;

/**
 * An event of the source's sheet that is not stored under its own key but has the date and name of an event imported
 * before under another key - from the organizer's previous sheet (PreviousSheetEvents). Either the one imported event
 * it is (importing it moves that event to the new key and updates it), or a problem when the match is not certain.
 */
readonly final class PreviousSheetMatch
{
    // Two or more imported events have its date and name - the organizer renames one (detail: how many)
    public const string MATCHES_SEVERAL_IMPORTED_EVENTS = 'matches_several_imported_events';
    // Another event of the sheet has the same date and name and matches the same imported event
    public const string SHARES_IMPORTED_EVENT = 'shares_imported_event';

    private function __construct(
        // The key the matched event is stored under; null for a problem
        public null|string $storedKey,
        // One of the constants above; null for a match
        public null|string $problem,
        public null|string $detail,
        // A never-imported item (waiting for its puzzle, a problem) is stored under the event's own key - it is
        // removed before the imported one moves to the key
        public bool $replacesUnimportedItem,
    ) {
    }

    public static function of(string $storedKey, bool $replacesUnimportedItem): self
    {
        return new self($storedKey, null, null, $replacesUnimportedItem);
    }

    public static function problem(string $problem, null|string $detail = null): self
    {
        return new self(null, $problem, $detail, false);
    }

    public function isProblem(): bool
    {
        return $this->problem !== null;
    }
}
