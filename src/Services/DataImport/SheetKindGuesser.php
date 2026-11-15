<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * Says what a sheet holds and what one of its rows is (docs/features/data-import/README.md "Which sheets are
 * puzzles", columns.md "Sheet kinds"), from the columns that were recognised and the sheet's name:
 *
 * - no puzzle name column → **not puzzles** (a drill log, statistics, a list of brands);
 * - a time, a solve date or an attempt number → **results**;
 * - a status column → the column says per row what the row is (a result, an owned puzzle, a wish);
 * - a name with brand, pieces or a barcode and nothing of a result → **puzzles I own** - or the **wish list** when
 *   the sheet's name says so.
 *
 * "A puzzle list other sheets point to" is not seen on one sheet: SheetLookup decides it across the workbook.
 * "Somebody else's" is never guessed - only the player knows.
 */
final readonly class SheetKindGuesser
{
    /** Folded words in a sheet's name that make a list of puzzles a wish list */
    private const array WISH_WORDS = ['wish', 'wishlist', 'wanted', 'want', 'prani', 'chci', 'wunsch', 'wunschliste', 'deseos', 'deseados', 'souhaits', 'envies', '欲しい', 'ほしい'];

    /**
     * @param list<MappedColumn> $columns
     *
     * @return array{DataImportSheetKind, ColumnCertainty, DataImportRowMeaning}
     */
    public function guess(string $sheetTitle, array $columns, null|KnownExportLayout $layout = null): array
    {
        if ($layout !== null) {
            return [$layout->kind, ColumnCertainty::Certain, $layout->rowMeaning];
        }

        $certainty = [];

        foreach ($columns as $column) {
            if ($column->field !== null) {
                $certainty[$column->field->value] = max($certainty[$column->field->value] ?? 0, $column->certainty->rank());
            }
        }

        $has = static fn (ImportField ...$fields): bool => array_filter($fields, static fn (ImportField $field): bool => isset($certainty[$field->value])) !== [];
        $sure = static fn (ImportField $field): bool => ($certainty[$field->value] ?? 0) === ColumnCertainty::Certain->rank();

        if ($has(ImportField::PuzzleName) === false) {
            return [
                DataImportSheetKind::NotPuzzles,
                $has(ImportField::Brand, ImportField::Pieces, ImportField::Barcode) ? ColumnCertainty::Probable : ColumnCertainty::Certain,
                DataImportRowMeaning::Result,
            ];
        }

        $result = $has(ImportField::Time, ImportField::SolvedOn, ImportField::AttemptNumber);

        if ($has(ImportField::Status)) {
            return [$result ? DataImportSheetKind::Results : DataImportSheetKind::Owned, ColumnCertainty::Probable, DataImportRowMeaning::ByColumn];
        }

        if ($result) {
            return [
                DataImportSheetKind::Results,
                $sure(ImportField::PuzzleName) && ($sure(ImportField::Time) || $sure(ImportField::SolvedOn)) ? ColumnCertainty::Certain : ColumnCertainty::Probable,
                DataImportRowMeaning::Result,
            ];
        }

        $words = explode(' ', trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', SearchText::fold($sheetTitle)) ?? ''));
        $title = SearchText::fold($sheetTitle);

        foreach (self::WISH_WORDS as $word) {
            if (in_array($word, $words, true) || (preg_match('/[\p{Han}\p{Hiragana}]/u', $word) === 1 && str_contains($title, $word))) {
                return [DataImportSheetKind::WishList, ColumnCertainty::Probable, DataImportRowMeaning::WishList];
            }
        }

        return [
            DataImportSheetKind::Owned,
            $has(ImportField::Brand, ImportField::Pieces, ImportField::Barcode) ? ColumnCertainty::Probable : ColumnCertainty::Unknown,
            DataImportRowMeaning::Owned,
        ];
    }
}
