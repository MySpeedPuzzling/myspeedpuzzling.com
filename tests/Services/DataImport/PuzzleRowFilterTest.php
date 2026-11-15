<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\ImportRowLeftOut;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;

/**
 * docs/features/data-import/README.md "Which rows are puzzles": the rows of a sheet that are no data - and the one
 * that only lacks its name. Every sheet here is made up.
 */
final class PuzzleRowFilterTest extends TestCase
{
    public function testRowsOfAResultsSheetThatAreNoData(): void
    {
        $rows = $this->parse('2024', [
            1 => ['Results of 2024'],
            3 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'On MSP'],
            4 => ['Lantern Festival', 'Ravensburger', 1000, Kit::date('2024-01-06'), Kit::duration(3907), true],
            // Blank inside the table: only a value beside it
            5 => [Kit::text('  '), null, null, null, null, null, null, 'a stray note'],
            // The table stretched down: an unticked checkbox, a formula over empty cells
            6 => [null, null, null, null, Kit::formula(Kit::duration(0)), false],
            // Prefilled, waiting for a date and a time
            7 => ['Mossy Steps', 'Pomegranate', 1000, null, null, false],
            // The header again
            8 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'On MSP'],
            9 => ['Sugar Mill', 'Eurographics', 1000, Kit::date('2024-05-25'), Kit::duration(4127), false],
            // Under the data
            10 => ['Total', '', Kit::formula(Kit::number(2000))],
            11 => [null, null, Kit::formula(Kit::number(1000)), null, Kit::formula(Kit::duration(4017))],
            12 => ['Average:', '', '', '', Kit::formula(Kit::duration(4017))],
            // A note typed into a data column
            13 => [null, 'all of these were gifts'],
            // The puzzle alone, on a results sheet: waiting to be filled in like row 7
            14 => ['Tidy Workshop'],
        ]);

        self::assertSame(
            [
                1 => ImportRowLeftOut::OutsideTable,
                3 => ImportRowLeftOut::Header,
                4 => null,
                5 => ImportRowLeftOut::Blank,
                6 => ImportRowLeftOut::DefaultsOnly,
                7 => ImportRowLeftOut::Template,
                8 => ImportRowLeftOut::RepeatedHeader,
                9 => null,
                10 => ImportRowLeftOut::Summary,
                11 => ImportRowLeftOut::Summary,
                12 => ImportRowLeftOut::Summary,
                13 => ImportRowLeftOut::NotAPuzzle,
                14 => ImportRowLeftOut::Template,
            ],
            array_map(static fn (ParsedImportRow $row): null|ImportRowLeftOut => $row->leftOut, $rows),
        );
    }

    public function testARowWithBrandAndPiecesButNoNameIsAProblemNotLeftOut(): void
    {
        $rows = $this->parse('To Do', [
            1 => ['Title', 'Brand', 'Pieces'],
            2 => ['', 'Buffalo', 2000],
            3 => ['', 'Buffalo', ''],
            4 => ['Just a name', '', ''],
        ]);

        self::assertNull($rows[2]->leftOut);
        self::assertSame([['field' => 'puzzle_name', 'code' => 'no_name']], $rows[2]->problems);
        self::assertNull($rows[2]->puzzleKey);
        // A brand alone is no puzzle; a name alone is none either (a list of names says nothing to pair by)
        self::assertSame(ImportRowLeftOut::NotAPuzzle, $rows[3]->leftOut);
        self::assertSame(ImportRowLeftOut::NotAPuzzle, $rows[4]->leftOut);
    }

    public function testAPuzzleNamedLikeASummaryWordStaysAPuzzle(): void
    {
        $rows = $this->parse('Keep', [
            1 => ['Title', 'Brand', 'Pieces'],
            2 => ['Total', 'Heye', 1000],
            3 => ['Total', '', Kit::formula(Kit::number(1000))],
        ]);

        self::assertNull($rows[2]->leftOut);
        self::assertSame(ImportRowLeftOut::Summary, $rows[3]->leftOut);
    }

    public function testASheetThatIsNotPuzzlesLeavesEveryRowOut(): void
    {
        $rows = $this->parse('Drills', [1 => ['Date', 'Time'], 2 => [Kit::date('2026-03-01'), Kit::duration(301)]]);

        self::assertSame([], $rows);
        $kit = new Kit();
        $sheet = Kit::sheet('Drills', [1 => ['Date', 'Time'], 2 => [Kit::date('2026-03-01'), Kit::duration(301)]]);
        $proposal = $kit->analyser->analyse($sheet);

        self::assertSame(ImportRowLeftOut::SheetLeftOut, $kit->parser->parse(2, $sheet->rows[2], $proposal->mapping, $proposal->options)->leftOut);
    }

    /**
     * @param array<int, list<mixed>> $rows
     *
     * @return array<int, ParsedImportRow>
     */
    private function parse(string $title, array $rows): array
    {
        return (new Kit())->workbook([Kit::sheet($title, $rows)])[$title]['rows'];
    }
}
