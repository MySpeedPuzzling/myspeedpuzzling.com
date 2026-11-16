<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\DataRegionSource;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestion;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * "This sheet needs the player's eye before anything else" (docs/features/data-import/columns.md "Check first",
 * guided-flow.md §5.3): a sheet whose rows are imported and whose reading is a guess where a wrong guess spoils
 * everything built on it -
 *
 * - it has **no header row** (every column is a guess from its values), or
 * - one of **the columns that decide what gets added** - the puzzle's name, the time of a results sheet, the brand -
 *   was guessed **from its values only** (`reason: values` / `brand_values`): no header stands behind it, or
 * - **the kind of the sheet could not be told at all** (`unknown` - not merely `probable`).
 *
 * A sheet whose header row was found and whose columns come from header words - even `probable` ones, even with a
 * kind that is only probable (every list of owned puzzles) - is NOT held: it keeps the line "We are not sure about
 * 1 sheet - have a look", and its puzzles are matched at once.
 *
 * Never a known export. A sheet a remembered layout was laid over is held only for the second reason: somebody said
 * what its columns are - except a deciding column the layout does not know, which is as much a guess as anywhere.
 *
 * Such a sheet carries the question ImportQuestionType::CheckColumns from its first proposal on. While it does, its
 * rows are read but held (PendingQuestionRows): no puzzle is built or matched from them and none can be added - and
 * the flow's one button is "Check how we read your file" (ImportFlow). The player's word on the sheet answers it:
 * "Yes, this is right" or any change of its columns (ConfirmImportMapping) - the question is never asked twice.
 *
 * Pure. Stored as a question so that everything that already knows "a sheet waits for the player" (the stuck-import
 * guards, the slots, "ready to add") treats a held sheet as waiting for the player, never as broken.
 */
final class ImportCheckFirst
{
    /** The one answer: "Yes, this is right" */
    public const string YES = 'yes';

    /** The sheet has no header row: every column was guessed from its values */
    public const string BECAUSE_NO_HEADER = 'no_header';
    /** A header row, but a deciding column was guessed from its values alone, or the kind could not be told */
    public const string BECAUSE_UNSURE = 'unsure';

    /** The reasons of a column no header stands behind */
    private const array GUESSED_FROM_VALUES = [MappedColumn::BY_VALUES, MappedColumn::BY_BRAND_VALUES];

    /**
     * Why the sheet needs a look first - null: it does not.
     *
     * @return null|self::BECAUSE_*
     */
    public static function reason(ImportMapping $mapping): null|string
    {
        $table = $mapping->table();

        if ($table === null || $mapping->isImported() === false || $mapping->layout !== null) {
            return null;
        }

        // A remembered layout says what the sheet is and what its columns are - but a deciding column it does not
        // know (a column without a header guessed as the brand) is still a guess: that is looked at below
        if ($mapping->remembered === null) {
            if ($table->source === DataRegionSource::NoHeader) {
                return self::BECAUSE_NO_HEADER;
            }

            if ($mapping->kindCertainty === ColumnCertainty::Unknown) {
                return self::BECAUSE_UNSURE;
            }
        }

        $deciding = [ImportField::PuzzleName, ImportField::Brand];

        if ($mapping->kind === DataImportSheetKind::Results || in_array($mapping->rowMeaning, [DataImportRowMeaning::Result, DataImportRowMeaning::Both], true)) {
            $deciding[] = ImportField::Time;
        }

        foreach ($deciding as $field) {
            $column = $mapping->column($field);

            if ($column !== null && in_array($column->reason, self::GUESSED_FROM_VALUES, true)) {
                return self::BECAUSE_UNSURE;
            }
        }

        return null;
    }

    /**
     * The question of a sheet as it was just proposed - null for a sheet that is sure.
     */
    public static function question(ImportMapping $mapping): null|ImportQuestion
    {
        $table = $mapping->table();

        if ($table === null || self::reason($mapping) === null) {
            return null;
        }

        return new ImportQuestion(
            ImportQuestionType::CheckColumns,
            $mapping->column(ImportField::PuzzleName)->column ?? $table->firstColumn,
            [self::YES],
            rows: $table->lastRow === null ? 0 : max(0, $table->lastRow - $table->firstRow + 1),
        );
    }

    /**
     * The mapping with the question in front of its others - or as it is, for a sheet that is sure.
     */
    public static function asked(ImportMapping $mapping): ImportMapping
    {
        if (self::isAsked($mapping)) {
            return $mapping;
        }

        $question = self::question($mapping);

        return $question === null ? $mapping : $mapping->withQuestions([$question, ...$mapping->questions]);
    }

    /**
     * The mapping after the player said "this is right" (or saved the sheet's columns): what was a guess is the
     * player's word now - every column that is a field or "not imported" is certain, by the player, and so is the
     * kind. The sheet reads as sure from then on: no "we are not sure" line, no "Our guess" badge, never asked again.
     * A column with a role (a known export's own) and one that is certain already stay as they are.
     */
    public static function confirmed(ImportMapping $mapping): ImportMapping
    {
        $columns = array_map(
            static fn (MappedColumn $column): MappedColumn => $column->role !== null || $column->certainty === ColumnCertainty::Certain
                ? $column
                : new MappedColumn($column->column, $column->header, $column->field, ColumnCertainty::Certain, MappedColumn::BY_PLAYER, $column->readyMade, null, null, $column->unit),
            $mapping->columns,
        );

        return new ImportMapping($mapping->kind, ColumnCertainty::Certain, $mapping->rowMeaning, $mapping->regions, $mapping->region, $columns, $mapping->questions, $mapping->layout, $mapping->remembered);
    }

    public static function isAsked(ImportMapping $mapping): bool
    {
        foreach ($mapping->questions as $question) {
            if ($question->type === ImportQuestionType::CheckColumns) {
                return true;
            }
        }

        return false;
    }
}
