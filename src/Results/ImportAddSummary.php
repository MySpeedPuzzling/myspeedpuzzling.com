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
        /** Rows that are ready - before the daily guard */
        public int $rowsReady,
        /** Of them, the rows this "Add" takes: all but the results over today's guard */
        public int $rowsTaken,
        /** What this "Add" would make: new results, puzzles for the collection, wish-list puzzles */
        public int $results,
        public int $owned,
        public int $wishList,
        /** What is ready and waits for tomorrow (the daily guard): results, and puzzles of rows that are both */
        public int $later,
        /** Rows this "Add" takes that would add nothing: everything they say is the player's already */
        public int $alreadyYours,
        /** Ready rows that are the same result as another row of the file (one result unless the player says two) */
        public int $equalRows,
        /** Results the player's imports may still add today (DataImportLimits::MAX_RESULTS_ADDED_PER_DAY) */
        public int $resultsLeftToday,
        /** Rows this "Add" takes that are kept with the player's unlisted puzzles - nothing of them is public */
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
        /**
         * The review's own parts (GetImportAddScreen::summary() with $forReview): the ready rows per sheet, and the
         * rows it shows first
         *
         * @var list<ImportAddSheetLine>
         */
        public array $sheets = [],
        /** @var list<string> the first new results */
        public array $sampleIds = [],
        /** @var list<string> the first rows that add nothing - already the player's */
        public array $knownIds = [],
        /** "Forget the spreadsheet's content": the rows' cells are gone - nothing more can be added from the import */
        public bool $wiped = false,
        /** Of $sheetsNotSetUp: sheets that have their columns and are only being read (again) */
        public int $sheetsBeingRead = 0,
        /** Results of the file's read sheets that have no time (a sheet without a time column) - said before an Add */
        public int $resultsWithoutTime = 0,
        /** Rows taken that add nothing because they are on the profile already (onProfileAlready()) */
        public int $onProfile = 0,
        /** Rows the player said "Don't import" about */
        public int $notImported = 0,
    ) {
    }

    /** Rows that are ready and wait for tomorrow: the daily guard */
    public function overDailyLimit(): int
    {
        return $this->rowsReady - $this->rowsTaken;
    }

    /** Rows "Add" would take now */
    public function rowsToTake(): int
    {
        return $this->rowsTaken;
    }

    /**
     * Rows an "Add" would take that add nothing because everything they say is on the profile already - not a row
     * kept just for the player, not a repeat of another row of the file. Its own count, with the very predicate of
     * the rows "What will be added" leaves out (GetImportAddScreen): the status sentence, the fold's title and the
     * list's numbers are one number.
     */
    public function onProfileAlready(): int
    {
        return $this->onProfile;
    }

    /** Every row an "Add" would take is on the profile already - nothing else is why there is nothing to add */
    public function everythingIsOnProfile(): bool
    {
        return $this->onProfile > 0 && $this->onProfile === $this->rowsTaken && $this->notImported === 0;
    }

    /**
     * Nothing of the file is left to add: the rows that are ready are all on the profile already (or repeat one
     * another), nothing waits for tomorrow and nothing is held back by a step. No "Add" is offered then - there is
     * nothing to press for.
     */
    public function nothingLeftToAdd(): bool
    {
        return $this->rowsTaken > 0 && $this->toAdd() === 0 && $this->later === 0 && $this->hasNotReady() === false;
    }

    /** What the button's number is: the things this "Add" would make */
    public function toAdd(): int
    {
        return $this->results + $this->owned + $this->wishList + $this->unlisted;
    }

    public function hasAdded(): bool
    {
        return $this->rowsAdded > 0;
    }

    /** Anything that keeps rows from being ready and that the player can settle or wait for */
    public function hasNotReady(): bool
    {
        return $this->undecided + $this->needLook + $this->sheetsNotSetUp + $this->needsExactDay + $this->notAdded > 0;
    }
}
