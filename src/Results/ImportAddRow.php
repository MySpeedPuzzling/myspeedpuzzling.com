<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One row of an import as the "Add" screens list it (GetImportAddScreen, docs/features/data-import/adding-screens.md):
 * what the row says, the catalogue puzzle it was paired with, and where it stands - ready, added, could not be
 * added (with the reason code). Everything in `said` is the player's own text: shown escaped, never put in a URL.
 */
readonly final class ImportAddRow
{
    public function __construct(
        public string $rowId,
        /** What the row says, as the import understood it */
        public ImportSheetRow $said,
        /** A DataImportRowState's value */
        public string $state,
        /** Why it was not added (ImportRowRefusal / ImportedResultRefusal) - null when nothing refused it */
        public null|string $failure,
        public null|string $importPuzzleId,
        public null|string $puzzleId,
        public null|string $puzzleName,
        public null|string $brandName,
        public null|int $piecesCount,
        public null|string $image,
        public null|float $imageRatio,
        /** The result the row made and that an Undo would take back */
        public null|string $solvingTimeId = null,
        public bool $madeCollectionItem = false,
        public bool $madeWishListItem = false,
        public null|DateTimeImmutable $addedAt = null,
        /** An Undo came to the row and left it: its result was changed since the import */
        public bool $keptByUndo = false,
        /** An Undo holds the row right now */
        public bool $beingTakenBack = false,
        public null|string $batchId = null,
        /** How many rows of the file are this very result (the row itself counted) - only where a list asks */
        public int $equalRows = 1,
        public bool $addAsSeparate = false,
    ) {
    }

    public function firstTry(): bool
    {
        return ($this->said->parsed['firstTry'] ?? null) === true;
    }

    /** solo / duo / team as the row says - null when it does not say */
    public function type(): null|string
    {
        $type = $this->said->parsed['type'] ?? null;

        return is_string($type) && in_array($type, ['solo', 'duo', 'team'], true) ? $type : null;
    }
}
