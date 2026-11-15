<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * A sheet of the signed-in player's import as the file has it (GetDataImports::sheetsOfPlayer()).
 */
readonly final class DataImportSheetOverview
{
    public function __construct(
        public int $position,
        public string $title,
        public bool $hidden,
        /** Rows with at least one value */
        public int $rows,
        /** The right-most column a value stands in */
        public int $columns,
        /** What the import takes the sheet for (docs/features/data-import/columns.md) - null: not recognised yet */
        public null|DataImportSheetKind $kind = null,
        /** The import has proposed its mapping */
        public bool $mapped = false,
        /** It has a question only the player can answer - its rows wait */
        public bool $waitsForAnAnswer = false,
        /** Its rows are parsed for its current mapping */
        public bool $parsed = false,
        /** Rows that are puzzles and hold nothing unclear */
        public int $rowsUnderstood = 0,
        /** Rows that are puzzles and wait with something to look at */
        public int $rowsNeedLook = 0,
        public int $rowsLeftOut = 0,
        /** Its set-up ended without its rows (a fault of ours) - nothing more happens to it by itself */
        public bool $failed = false,
        public string $sheetId = '',
        /** The version a change of the player must name (ConfirmImportMapping) */
        public int $mappingVersion = 0,
        /** Why its set-up ended (DataImportSheet::SET_UP_*) - null while nothing failed */
        public null|string $failure = null,
        /** Rows a question that is not answered yet decides - neither ready nor "need a look" */
        public int $rowsWaiting = 0,
        /** How many questions the sheet asks */
        public int $questions = 0,
        /** A layout the player saved earlier was applied to it (docs/features/data-import/layouts.md) */
        public null|RememberedLayout $rememberedLayout = null,
    ) {
    }

    /**
     * What the "Sheets and columns" screen says about the sheet (docs/features/data-import/columns-screen.md "What
     * each state shows").
     */
    public function state(): string
    {
        return self::stateOf($this->failed, $this->mapped, $this->isLeftOut(), $this->parsed, $this->questions > 0, $this->rowsUnderstood, $this->rowsNeedLook, $this->rowsWaiting);
    }

    /**
     * The one rule of a sheet's state: `failed`, `recognising`, `parsing`, `left_out` (not puzzles, somebody
     * else's), `waiting` - for the player's answer **as a whole**, which it is only when its questions decide at
     * least half of its rows - or `parsed` (its counts; a question that decides a few rows is a button beside them).
     */
    public static function stateOf(bool $failed, bool $mapped, bool $leftOut, bool $parsed, bool $asks, int $understood, int $needLook, int $waiting): string
    {
        return match (true) {
            $failed => 'failed',
            $mapped === false => 'recognising',
            $parsed === false => 'parsing',
            $leftOut => 'left_out',
            $asks && $waiting > 0 && $waiting * 2 >= $understood + $needLook + $waiting => 'waiting',
            default => 'parsed',
        };
    }

    /**
     * Whether an answer of the player is due: a question, on a sheet that is data and did not fail.
     */
    public function asksThePlayer(): bool
    {
        return $this->questions > 0 && $this->failed === false && $this->isLeftOut() === false;
    }

    public function isLeftOut(): bool
    {
        return $this->kind === DataImportSheetKind::NotPuzzles || $this->kind === DataImportSheetKind::SomebodyElse;
    }
}
