<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * How far an import is - what its page shows while the file is read (GetDataImports::progressOfPlayer(), published
 * by ImportProgress). Numbers and states only: nothing of the file's content.
 */
readonly final class DataImportProgress
{
    /** Not a DataImportStatus: the import was closed because the player already has the very same file */
    public const string SAME_FILE = 'same_file';

    public function __construct(
        public string $importId,
        public string $playerId,
        /** A DataImportStatus's value, or SAME_FILE */
        public string $status,
        public null|int $sheetsTotal,
        public int $sheetsRead,
        public int $rowsRead,
        public null|string $readFailure,
        public null|string $sameFileAsImportId,
        /** Sheets the import has proposed a mapping for (docs/features/data-import/columns.md) */
        public int $sheetsMapped = 0,
        /** Of those: sheets whose rows are parsed for their current mapping */
        public int $sheetsParsed = 0,
        /** Of those: sheets that wait for the player's answer */
        public int $sheetsWaiting = 0,
        /** Sheets a step that runs by itself is still due for: no mapping yet, or rows not read for the current one */
        public int $sheetsWorking = 0,
        /** The rows of the sheets being read for their current mapping - and how many of them are done */
        public int $rowsToParse = 0,
        public int $rowsParsed = 0,
        /** Another one whenever a sheet's mapping, parse or failure changed - never anything of the file */
        public string $revision = '',
        /**
         * The pairing of the import's puzzles with the catalogue (docs/features/data-import/pairing-screens.md "The
         * live line"): whether a run is under way, the counts by what is left to do, and a revision that changes
         * when a run starts or comes to rest. Numbers only.
         *
         * @var array{running: bool, puzzles: int, found: int, probably: int, choose: int, notFound: int, searching: int, revision: string}
         */
        public array $pairing = ['running' => false, 'puzzles' => 0, 'found' => 0, 'probably' => 0, 'choose' => 0, 'notFound' => 0, 'searching' => 0, 'revision' => ''],
    ) {
    }

    /**
     * The import as a puzzles' screen shows it: another one whenever a sheet was read again or the puzzles were
     * looked up again - what one answer for a whole pile is given for (DataImportPileAnswer).
     */
    public function puzzlesRevision(): string
    {
        return $this->revision . '|' . $this->pairing['revision'];
    }

    /**
     * Whether the page still waits for something to happen by itself.
     */
    public function isMoving(): bool
    {
        return in_array($this->status, [DataImportStatus::Fetching->value, DataImportStatus::Uploaded->value, DataImportStatus::Reading->value], true);
    }

    /**
     * What travels to the page - by the JSON endpoint and by the player's private topic alike.
     *
     * @return array{type: string, importId: string, status: string, moving: bool, sheetsTotal: null|int, sheetsRead: int, rowsRead: int, sheetsMapped: int, sheetsParsed: int, sheetsWaiting: int, working: bool, rowsToParse: int, rowsParsed: int, revision: string, pairing: array{running: bool, puzzles: int, found: int, probably: int, choose: int, notFound: int, searching: int, revision: string}}
     */
    public function toArray(): array
    {
        return [
            'type' => 'import_progress',
            'importId' => $this->importId,
            'status' => $this->status,
            'moving' => $this->isMoving(),
            'sheetsTotal' => $this->sheetsTotal,
            'sheetsRead' => $this->sheetsRead,
            'rowsRead' => $this->rowsRead,
            'sheetsMapped' => $this->sheetsMapped,
            'sheetsParsed' => $this->sheetsParsed,
            'sheetsWaiting' => $this->sheetsWaiting,
            'working' => $this->sheetsWorking > 0,
            'rowsToParse' => $this->rowsToParse,
            'rowsParsed' => min($this->rowsParsed, $this->rowsToParse),
            'revision' => $this->revision,
            'pairing' => $this->pairing,
        ];
    }
}
