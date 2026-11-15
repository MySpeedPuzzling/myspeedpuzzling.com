<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImportRowMeaning;
use SpeedPuzzling\Web\Value\DataImportSheetKind;

/**
 * docs/features/data-import/columns.md "Sheet kinds". Every sheet here is made up.
 */
final class SheetKindGuesserTest extends TestCase
{
    public function testASheetWithATimeOrASolveDateHoldsResults(): void
    {
        self::assertSame(
            [DataImportSheetKind::Results, ColumnCertainty::Certain, DataImportRowMeaning::Result],
            $this->guess('2022', [1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time'], 2 => ['Attic Window', 'Heye', 1000, Kit::date('2022-01-03'), Kit::duration(4011)]]),
        );
    }

    public function testAListOfPuzzlesWithoutAnythingOfAResultIsOwned(): void
    {
        self::assertSame(
            [DataImportSheetKind::Owned, ColumnCertainty::Probable, DataImportRowMeaning::Owned],
            $this->guess('To Do', [1 => ['Title', 'Brand', 'Pieces'], 2 => ['Quiet Canal', 'Heye', 1000]]),
        );
    }

    public function testTheSheetsNameMakesAListAWishList(): void
    {
        foreach (['Wish list', 'My wishlist 2026', 'Seznam přání', 'Wunschliste', 'ほしい物'] as $title) {
            self::assertSame(
                [DataImportSheetKind::WishList, ColumnCertainty::Probable, DataImportRowMeaning::WishList],
                $this->guess($title, [1 => ['Title', 'Brand', 'Pieces'], 2 => ['Quiet Canal', 'Heye', 1000]]),
                $title,
            );
        }
    }

    public function testAStatusColumnSaysPerRowWhatARowIs(): void
    {
        self::assertSame(
            [DataImportSheetKind::Owned, ColumnCertainty::Probable, DataImportRowMeaning::ByColumn],
            $this->guess('Puzzles', [1 => ['Title', 'Brand', 'Completed?'], 2 => ['Quiet Canal', 'Heye', 'Y'], 3 => ['Paper Kites', 'Trefl', '']]),
        );
    }

    public function testWithoutAPuzzleNameASheetIsNotPuzzles(): void
    {
        // A drill log: dates and times, no puzzle
        self::assertSame(DataImportSheetKind::NotPuzzles, $this->guess('Drills', [1 => ['Date', 'Time', 'Sorted'], 2 => [Kit::date('2026-03-01'), Kit::duration(301), 120]])[0]);
        // Statistics
        self::assertSame(DataImportSheetKind::NotPuzzles, $this->guess('Stats', [1 => ['Month', 'Solved', 'Hours'], 2 => ['January', Kit::formula(Kit::number(4)), Kit::formula(Kit::number(3.4))]])[0]);
        // A list of brands - not sure, it has a brand
        self::assertSame(
            [DataImportSheetKind::NotPuzzles, ColumnCertainty::Probable],
            array_slice($this->guess('Brands', [1 => ['Brand', 'Count'], 2 => ['Trefl', Kit::formula(Kit::number(1))]]), 0, 2),
        );
        // Nothing at all
        self::assertSame([DataImportSheetKind::NotPuzzles, ColumnCertainty::Certain], array_slice($this->guess('Empty', []), 0, 2));
    }

    public function testTheListTheResultsPointToIsThePuzzleList(): void
    {
        $workbook = (new Kit())->workbook(ImportShapes::trainingWorkbook());

        self::assertSame(
            ['Puzzle List' => 'puzzle_list', '500 Solo' => 'results', 'Pairs' => 'results', 'Speed run' => 'results', 'Drills' => 'not_puzzles', 'Stats' => 'not_puzzles', 'Brands' => 'not_puzzles'],
            array_map(static fn (array $sheet): string => $sheet['proposal']->mapping->kind->value, $workbook),
        );
        self::assertSame(ColumnCertainty::Certain, $workbook['Puzzle List']['proposal']->mapping->kindCertainty);
        // The sheets without a brand or a pieces column look there; the one that has both does not
        self::assertSame(
            ['Puzzle List', null, 'Puzzle List'],
            [$workbook['500 Solo']['proposal']->options->lookupSheetId, $workbook['Pairs']['proposal']->options->lookupSheetId, $workbook['Speed run']['proposal']->options->lookupSheetId],
        );
    }

    /**
     * @param array<int, list<mixed>> $rows
     *
     * @return array{DataImportSheetKind, ColumnCertainty, DataImportRowMeaning}
     */
    private function guess(string $title, array $rows): array
    {
        $mapping = (new Kit())->analyser->analyse(Kit::sheet($title, $rows))->mapping;

        return [$mapping->kind, $mapping->kindCertainty, $mapping->rowMeaning];
    }
}
