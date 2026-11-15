<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * What an "Add" of everything that is ready would do for one import of the signed-in player, and what is not ready
 * yet and why (GetImportAddScreen::summary(), docs/features/data-import/adding-screens.md "Ready to add"). Numbers
 * only. It says what the import's pages show before the button is pressed - the engine decides again, row by row,
 * when it adds (docs/features/data-import/adding.md).
 */
readonly final class ImportAddSummary
{
    public function __construct(
        /** The safety switch: no "Add" starts while it is on */
        public bool $paused,
        /** The import is at a stage rows can be added from (set up, its rows' content not wiped) */
        public bool $canAdd,
        /** An "Add" of this import that still runs */
        public null|string $runningBatchId,
        public null|string $latestBatchId,
        /** Rows an "Add" of everything would take - before the daily guard */
        public int $rowsReady,
        /** Of them, rows that carry a result (what the daily guard counts) */
        public int $readyResultRows,
        /** New results, puzzles for the collection and wish-list puzzles those rows would make */
        public int $results,
        public int $owned,
        public int $wishList,
        /** Ready rows that would add nothing: everything they say is the player's already */
        public int $alreadyYours,
        /** Ready rows that are the same result as another row of the file (one result unless the player says two) */
        public int $equalRows,
        /** Results the player's imports may still add today (DataImportLimits::MAX_RESULTS_ADDED_PER_DAY) */
        public int $resultsLeftToday,
        /** Not ready: the puzzle is kept as an unlisted puzzle */
        public int $unlisted,
        /** Not ready: a result without an exact day */
        public int $needsExactDay,
        /** Not ready: rows whose puzzle waits for the player's choice */
        public int $undecided,
        /** Not ready: rows that need a look */
        public int $needLook,
        /** Not ready: sheets whose columns are not set up (or are being read again) */
        public int $sheetsNotSetUp,
        /** Rows an earlier "Add" could not add - they wait with their reason */
        public int $notAdded,
        public int $rowsAdded,
        public int $resultsAdded,
        public int $collectionItemsAdded,
        public int $wishListItemsAdded,
        public null|DateTimeImmutable $lastAddedAt,
    ) {
    }

    /** Results that are ready and wait for tomorrow: the daily guard */
    public function overDailyLimit(): int
    {
        return max(0, $this->readyResultRows - max(0, $this->resultsLeftToday));
    }

    /** Rows "Add" would take now */
    public function rowsToTake(): int
    {
        return $this->rowsReady - $this->overDailyLimit();
    }

    /** What the button's number is: the things an "Add" would make */
    public function toAdd(): int
    {
        return $this->results + $this->owned + $this->wishList;
    }

    public function hasAdded(): bool
    {
        return $this->rowsAdded > 0;
    }

    /** Anything that keeps rows from being ready and that the player can settle or wait for */
    public function hasNotReady(): bool
    {
        return $this->undecided + $this->needLook + $this->sheetsNotSetUp + $this->unlisted + $this->needsExactDay + $this->notAdded > 0;
    }
}
