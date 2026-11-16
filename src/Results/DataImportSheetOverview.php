<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\RememberedLayout;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\PuzzlingType;

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
        /** What a row of the sheet is (a DataImportRowMeaning) - null: not recognised yet */
        public null|string $rowMeaning = null,
        /** Solo, pair or team for the whole sheet (a PuzzlingType) - null: each row says it, or nothing does */
        public null|string $solvingType = null,
        /**
         * What its columns were recognised as (ImportField values, each once, in the sheet's order)
         *
         * @var list<string>
         */
        public array $fieldsRecognised = [],
        /** Columns the sheet's own page lists under "Please check these": not certain, and no role of their own */
        public int $columnsToCheck = 0,
        /** MySpeedPuzzling is sure what the sheet is (or the player said it) */
        public bool $kindSure = true,
        /** The export of an app we know (ImportMapping::$layout) - null: a sheet of the player's own making */
        public null|string $knownExport = null,
        /**
         * How its dates and times are read (Services\DataImport\ImportSheetReadings::of())
         *
         * @var list<array{type: string, value: string}>
         */
        public array $readings = [],
        /**
         * The sheet's reading is a guess the player is asked to check before anything is built on it
         * (Services\DataImport\ImportCheckFirst): why - `no_header` or `unsure`. null: it need not be checked first.
         * While it is set the sheet's rows are held: none is matched with a puzzle and none can be added.
         */
        public null|string $checkFirst = null,
    ) {
    }

    /**
     * Its rows are held until the player has looked at how it was read (ImportCheckFirst) - on a sheet that is
     * imported and did not fail.
     */
    public function isHeldForACheck(): bool
    {
        return $this->checkFirst !== null && $this->mapped && $this->failed === false && $this->isLeftOut() === false;
    }

    /**
     * What the sheet's rows are **added as**, as the key of one sentence (docs/features/data-import/guided-flow.md
     * §5.4, the sentence table): `solo`, `pair`, `team`, `results_each` (each row says it), `both`, `owned`, `wish`,
     * `by_column`, `puzzle_list` - null for a sheet that is not imported or not recognised yet.
     */
    public function addedAs(): null|string
    {
        if ($this->kind === null || $this->rowMeaning === null || $this->isLeftOut()) {
            return null;
        }

        if ($this->kind === DataImportSheetKind::PuzzleList) {
            return 'puzzle_list';
        }

        return match ($this->rowMeaning) {
            DataImportRowMeaning::Both->value => 'both',
            DataImportRowMeaning::Owned->value => 'owned',
            DataImportRowMeaning::WishList->value => 'wish',
            DataImportRowMeaning::ByColumn->value => 'by_column',
            default => match (true) {
                $this->solvingType === PuzzlingType::Duo->value => 'pair',
                $this->solvingType === PuzzlingType::Team->value => 'team',
                $this->solvingType === PuzzlingType::Solo->value => 'solo',
                // No column names people or says pair / team: every row is a solo result
                array_intersect($this->fieldsRecognised, [ImportField::SolvedWith->value, ImportField::SolvingType->value]) === [] => 'solo',
                default => 'results_each',
            },
        };
    }

    /**
     * The rows the sentence counts: the sheet's puzzle rows once they are read, its rows with a value until then.
     */
    public function puzzleRows(): int
    {
        return $this->parsed ? $this->rowsUnderstood + $this->rowsNeedLook + $this->rowsWaiting : $this->rows;
    }

    /**
     * "Please check" (guided-flow.md §5.4): MySpeedPuzzling is not sure what the sheet is, or - on a sheet that is
     * imported - what one of its columns is. Such a sheet comes first on the summary of step 2, with "!".
     */
    public function isUnsure(): bool
    {
        if ($this->mapped === false || $this->failed) {
            return false;
        }

        return $this->kindSure === false || $this->isHeldForACheck() || ($this->isLeftOut() === false && $this->columnsToCheck > 0);
    }

    /**
     * What the "Sheets and columns" screen says about the sheet (docs/features/data-import/columns-screen.md "What
     * each state shows").
     */
    public function state(): string
    {
        return self::stateOf($this->failed, $this->mapped, $this->isLeftOut(), $this->parsed, $this->questions > 0 || $this->checkFirst !== null, $this->rowsUnderstood, $this->rowsNeedLook, $this->rowsWaiting);
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
