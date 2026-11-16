<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\DataImport\DataRegionFinder;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\DataRegionSource;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportLimits;

/**
 * docs/features/data-import/columns.md "Where the data is": the header row and the table(s) of a sheet. Every sheet
 * here is made up.
 */
final class DataRegionFinderTest extends TestCase
{
    private DataRegionFinder $finder;

    protected function setUp(): void
    {
        $this->finder = (new Kit())->regionFinder;
    }

    public function testAnExcelTableUnderADashboardSaysItExactly(): void
    {
        $regions = $this->finder->find(Kit::sheet('500 Solo', [
            1 => ['Solved', Kit::formula(Kit::number(14))],
            2 => ['Average', Kit::formula(Kit::duration(2893))],
            6 => ['Puzzle', 'Date', 'Time'],
            7 => ['Circle of Kites', Kit::date('2026-01-10'), Kit::duration(2893)],
            8 => ['Total', '', Kit::formula(Kit::duration(2893))],
        ], ['tables' => [['name' => 'Solo500', 'range' => 'A6:C8', 'headerRow' => 6, 'totalsRows' => 1]]]));

        self::assertCount(1, $regions);
        self::assertSame(DataRegionSource::Table, $regions[0]->source);
        self::assertSame([6, 7, 7, 1, 3, 'Solo500'], [$regions[0]->headerRow, $regions[0]->firstRow, $regions[0]->lastRow, $regions[0]->firstColumn, $regions[0]->lastColumn, $regions[0]->name]);
        self::assertSame('A6:C7', $regions[0]->range());
    }

    public function testTwentySmallTablesSideBySideAreTwentyRegions(): void
    {
        $drills = null;

        foreach (ImportShapes::trainingWorkbook() as $sheet) {
            $drills = $sheet->title === 'Drills' ? $sheet : $drills;
        }

        self::assertNotNull($drills);
        $regions = $this->finder->find($drills);

        self::assertCount(20, $regions);
        // Equal in size: the left-most is offered
        self::assertSame('A6:C9', $regions[0]->range());
        self::assertSame('BY6:CA9', $regions[19]->range());
        self::assertSame(['Date', 'Time', 'Sorted'], array_values(DataRegionFinder::headers($drills, $regions[19])));
    }

    public function testWithoutATableTheRowAboveAFrozenPaneIsTheHeader(): void
    {
        $regions = $this->finder->find(Kit::sheet('Log', [
            1 => ['My jigsaw log'],
            3 => ['What', 'How many', 'How long'],
            4 => ['Sleepy Tram', 1000, Kit::duration(4157)],
        ], ['frozenPane' => ['row' => 4, 'column' => 1]]));

        self::assertSame([DataRegionSource::FrozenPane, 3, 4, null], [$regions[0]->source, $regions[0]->headerRow, $regions[0]->firstRow, $regions[0]->lastRow]);
    }

    public function testKnownColumnNamesUnderATitleAndADashboardAreTheHeader(): void
    {
        $regions = $this->finder->find(Kit::sheet('2022', [
            1 => ['Results of the year'],
            2 => ['Solved', Kit::formula(Kit::number(2)), 'Average', Kit::formula(Kit::duration(4355))],
            4 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time'],
            5 => ['Attic Window', 'Ravensburger', 1000, Kit::date('2022-01-03'), Kit::duration(4011)],
            6 => ['Fig Tree Lane', 'Cobble Hill', 1000, Kit::date('2022-03-19'), Kit::duration(4699)],
        ]));

        self::assertSame([DataRegionSource::HeaderWords, 4, 'A4:E'], [$regions[0]->source, $regions[0]->headerRow, $regions[0]->range()]);
        self::assertSame(2, $regions[0]->rows);
    }

    public function testAHeaderOnRowSevenUnderSummaryAndLegendCellsIsFoundAlsoWhenItsCellsWrapOntoSeveralLines(): void
    {
        // A log somebody decorated: a title, totals worked out by formulas, a legend - then the header on row 7,
        // some of its cells wrapped onto several lines
        $sample = Kit::sheet('Solo 500', [
            1 => ['My 500 piece log', null, null, null, null],
            2 => ['Puzzles finished', Kit::formula(Kit::number(3)), null, 'Fastest', Kit::formula(Kit::duration(2105))],
            3 => ['Average', Kit::formula(Kit::duration(3411)), null, 'Slowest', Kit::formula(Kit::duration(4250))],
            4 => [null, null, null, null, null],
            5 => ['Legend', 'green = personal best', 'grey = repeat', null, null],
            6 => ['Updated', Kit::date('2026-05-02'), null, null, null],
            7 => ["Puzzle\nname", 'Brand', "Number of\npieces", "Date\ncompleted", "Time\n(h:mm:ss)"],
            8 => ['Harbour at Dusk', 'Oakfield', 500, Kit::date('2026-01-18'), Kit::duration(4250)],
            9 => ['Paper Kites', 'Northwind Puzzles', 500, Kit::date('2026-03-02'), Kit::duration(2105)],
            10 => ['The Old Mill', 'Paper Lantern', 500, Kit::date('2026-04-27'), Kit::duration(3880)],
        ]);
        $regions = $this->finder->find($sample);

        self::assertSame([DataRegionSource::HeaderWords, 7, 8, 1, 5], [$regions[0]->source, $regions[0]->headerRow, $regions[0]->firstRow, $regions[0]->firstColumn, $regions[0]->lastColumn]);
        self::assertSame(["Puzzle\nname", 'Brand', "Number of\npieces", "Date\ncompleted", "Time\n(h:mm:ss)"], array_values(DataRegionFinder::headers($sample, $regions[0])));

        // And every column under it is what its wrapped header says
        $kit = new Kit();
        $columns = $kit->analyser->analyse($sample)->mapping->columns;
        self::assertSame(
            [ImportField::PuzzleName, ImportField::Brand, ImportField::Pieces, ImportField::SolvedOn, ImportField::Time],
            array_map(static fn (MappedColumn $column): null|ImportField => $column->field, $columns),
        );
    }

    public function testHeadersNobodyKnowsAreFoundAsTextsOverRowsOfOtherTypes(): void
    {
        $regions = $this->finder->find(Kit::sheet('Sheet1', [
            1 => ['Everything I finished'],
            2 => ['Motif', 'Dielov', 'Trvanie'],
            3 => ['Sleepy Tram', 1000, Kit::duration(4157)],
            4 => ['Red Umbrellas', 500, Kit::duration(2203)],
        ]));

        self::assertSame([DataRegionSource::Types, 2], [$regions[0]->source, $regions[0]->headerRow]);
    }

    public function testASheetWithoutAHeaderStartsAtItsFirstRow(): void
    {
        $regions = $this->finder->find(Kit::sheet('', [
            1 => ['Sleepy Tram', 1000, Kit::duration(4157)],
            2 => ['Red Umbrellas', 500, Kit::duration(2203)],
        ]));

        self::assertSame([DataRegionSource::NoHeader, null, 1, 2], [$regions[0]->source, $regions[0]->headerRow, $regions[0]->firstRow, $regions[0]->rows]);
    }

    public function testTablesSideBySideAreSplitAtAnEmptyColumnAndTheLargestComesFirst(): void
    {
        $sheet = new SheetSample('Piles', [
            1 => Kit::row(['Title', 'Brand']) + Kit::row(['Puzzle', 'Pieces', 'Time'], 4),
            2 => Kit::row(['Quiet Canal', 'Heye']) + Kit::row(['Paper Kites', 1000, Kit::duration(3600 + 61)], 4),
            3 => Kit::row(['Paper Boats', 'Galison']) + Kit::row(['Tin Robots', 500, Kit::duration(1999)], 4),
            // Somebody's notes far to the right, under no header: not a table
            4 => Kit::row(['ask Ina about this one'], 9),
        ], null, 4);

        $regions = $this->finder->find($sheet);

        self::assertSame(['D1:F', 'A1:B'], array_map(static fn ($region): string => $region->range(), $regions));
        self::assertSame(['Puzzle', 'Pieces', 'Time'], array_values(DataRegionFinder::headers($sheet, $regions[0])));
    }

    public function testAColumnWithoutAHeaderInsideTheTableStaysInIt(): void
    {
        $sheet = Kit::sheet('Keep', [
            1 => ['Title', 'Brand', '', 'Pieces'],
            2 => ['Quiet Canal', 'Heye', 'landscape', 1000],
        ]);

        self::assertSame('A1:D', $this->finder->find($sheet)[0]->range());
    }

    public function testATableWiderThanAMappedRegionMayBeIsCutOnItsRight(): void
    {
        $header = ['Puzzle', 'Brand', 'Pieces'];
        $values = ['Quiet Canal', 'Heye', 1000];

        for ($column = 4; $column <= 70; $column++) {
            $header[] = 'Extra ' . $column;
            $values[] = $column;
        }

        $region = $this->finder->find(Kit::sheet('Wide', [1 => $header, 2 => $values]))[0];

        self::assertSame([1, DataImportLimits::MAX_REGION_COLUMNS, true], [$region->firstColumn, $region->lastColumn, $region->cut]);
    }

    public function testAKnownExportsHeaderRowIsFoundAsItIs(): void
    {
        $sheet = ImportShapes::puzzleTrackerExport()[0];
        $region = $this->finder->find($sheet)[0];

        self::assertSame([DataRegionSource::HeaderWords, 1, 'A1:W'], [$region->source, $region->headerRow, $region->range()]);
    }

    public function testASheetWithoutValuesHasNoTable(): void
    {
        self::assertSame([], $this->finder->find(new SheetSample('Empty', [])));
    }
}
