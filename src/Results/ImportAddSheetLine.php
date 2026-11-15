<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One sheet of an import as "Check and add" says it before the button (GetImportAddScreen::summary() with
 * $forReview, docs/features/data-import/guided-flow.md §5.9 "How we read your file"): what the sheet's ready rows
 * will be **added as** - results (solo, pair, team - and with whom), puzzles the player owns, wishes -, how its dates
 * and times were read, and one row of it as the example. Everything guessed about a sheet is a line here before
 * anything is added. Numbers count the rows this "Add" takes. The texts are the player's own: shown escaped, never
 * put in a URL.
 */
readonly final class ImportAddSheetLine
{
    /**
     * @param list<string> $people the first people the sheet's results name, as written (names, `#codes`)
     * @param list<array{type: string, value: string}> $readings how the sheet's values are read
     *        (Services\DataImport\ImportSheetReadings): `date_order`, `duration`, `short_dates`, `year`
     * @param array{time: null|string, date: null|string} $exampleSaid what the example row's time and date cells
     *        say in the file, as the player wrote them
     */
    public function __construct(
        public string $sheetId,
        public string $title,
        /** The version a change of the sheet must name (ConfirmImportMapping) */
        public int $mappingVersion,
        /** Rows of the sheet this "Add" takes */
        public int $ready,
        /** Of them, rows with a result - and how many of each solving type */
        public int $results,
        public int $solo,
        public int $duo,
        public int $team,
        /** Rows that say a puzzle the player owns (a row that is a result too is counted in both) */
        public int $owned,
        /** Rows that are a result and a puzzle the player owns at once */
        public int $both,
        /** Rows for the wish list */
        public int $wish,
        /** Results whose day the file knows to the month or the year only */
        public int $coarseDates,
        public array $people,
        public array $readings = [],
        public null|string $exampleId = null,
        public array $exampleSaid = ['time' => null, 'date' => null],
    ) {
    }

    /** Rows that are only a puzzle the player owns */
    public function onlyOwned(): int
    {
        return max(0, $this->owned - $this->both);
    }
}
