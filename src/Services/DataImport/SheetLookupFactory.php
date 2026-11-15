<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Services\DataImport\Reading\PiecesReader;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetProposal;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * Builds a SheetLookup from a puzzle list's stored rows, and finds across a workbook which sheet is "the puzzle
 * list the others point to" (docs/features/data-import/README.md "Which sheets are puzzles"): a list of puzzles
 * with brand or pieces that holds most of the names of a results sheet that lacks one of the two.
 */
final readonly class SheetLookupFactory
{
    public function __construct(
        private PiecesReader $piecesReader,
    ) {
    }

    /**
     * @param iterable<int, array<int, array<string, mixed>>> $rows the list sheet's rows: row number => cells
     */
    public function build(ImportMapping $list, iterable $rows): SheetLookup
    {
        $lookup = new SheetLookup();
        $table = $list->table();
        $name = $list->column(ImportField::PuzzleName);
        $brand = $list->column(ImportField::Brand);
        $pieces = $list->column(ImportField::Pieces);

        if ($table === null || $name === null) {
            return $lookup;
        }

        foreach ($rows as $number => $cells) {
            if ($table->holdsRow($number) === false || isset($cells[$name->column]) === false) {
                continue;
            }

            $lookup->add(
                ImportText::clean(StoredCells::shown($cells[$name->column])),
                $brand === null ? null : ImportText::clean(StoredCells::shown($cells[$brand->column] ?? null)),
                $pieces === null ? null : $this->piecesReader->read(StoredCells::input($cells[$pieces->column] ?? null))->pieces,
            );
        }

        return $lookup;
    }

    /**
     * The puzzle names of a sheet's table.
     *
     * @param iterable<int, array<int, array<string, mixed>>> $rows
     *
     * @return list<string>
     */
    public function names(ImportMapping $mapping, iterable $rows): array
    {
        $table = $mapping->table();
        $name = $mapping->column(ImportField::PuzzleName);
        $names = [];

        foreach ($rows as $number => $cells) {
            if ($table !== null && $name !== null && $table->holdsRow($number) && StoredCells::isBlank($cells[$name->column] ?? null) === false) {
                $names[] = ImportText::clean(StoredCells::shown($cells[$name->column] ?? null));
            }
        }

        return $names;
    }

    /**
     * Whether a sheet's results may lack what a puzzle list has: results without a brand column or without a pieces
     * column.
     */
    public static function needsALookup(ImportMapping $mapping): bool
    {
        return $mapping->kind === DataImportSheetKind::Results
            && $mapping->layout === null
            && $mapping->has(ImportField::PuzzleName)
            && ($mapping->has(ImportField::Brand) === false || $mapping->has(ImportField::Pieces) === false);
    }

    /**
     * Whether a sheet could be the list: puzzles I own (as far as one sheet tells) with a name and a brand or a
     * piece count.
     */
    public static function couldBeTheList(ImportMapping $mapping): bool
    {
        return $mapping->kind === DataImportSheetKind::Owned
            && $mapping->has(ImportField::PuzzleName)
            && ($mapping->has(ImportField::Brand) || $mapping->has(ImportField::Pieces));
    }

    /**
     * Across the workbook: every sheet that needs a lookup is pointed at the list holding most of its names (at
     * least SheetLookup::COVERS_FROM of them), and that list becomes the "puzzle list".
     *
     * @param array<string, ImportSheetProposal> $proposals by sheet id
     * @param array<string, list<string>> $names by sheet id: puzzle names of the sheets that need a lookup (a sample)
     * @param array<string, SheetLookup> $lists by sheet id: the lookups of the sheets that could be the list
     *
     * @return array<string, ImportSheetProposal>
     */
    public static function link(array $proposals, array $names, array $lists): array
    {
        foreach ($names as $sheetId => $sheetNames) {
            $best = null;
            $covered = 0.0;

            foreach ($lists as $listId => $list) {
                $coverage = $list->coverage($sheetNames);

                if ($listId !== $sheetId && $coverage > $covered) {
                    $best = $listId;
                    $covered = $coverage;
                }
            }

            if ($best === null || $covered < SheetLookup::COVERS_FROM || isset($proposals[$sheetId], $proposals[$best]) === false) {
                continue;
            }

            $proposals[$sheetId] = $proposals[$sheetId]->with($proposals[$sheetId]->mapping, $proposals[$sheetId]->options->withLookupSheet($best));
            $certain = $covered >= SheetLookup::SURELY_COVERS_FROM || $proposals[$best]->mapping->kindCertainty === ColumnCertainty::Certain;
            $proposals[$best] = $proposals[$best]->with(
                $proposals[$best]->mapping->withKind(DataImportSheetKind::PuzzleList, $certain ? ColumnCertainty::Certain : ColumnCertainty::Probable),
                $proposals[$best]->options,
            );
        }

        return $proposals;
    }
}
