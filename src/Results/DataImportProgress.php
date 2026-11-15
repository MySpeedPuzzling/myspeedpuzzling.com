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
    ) {
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
     * @return array{type: string, importId: string, status: string, moving: bool, sheetsTotal: null|int, sheetsRead: int, rowsRead: int, sheetsMapped: int, sheetsParsed: int, sheetsWaiting: int}
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
        ];
    }
}
