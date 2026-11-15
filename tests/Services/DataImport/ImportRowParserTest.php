<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\DateOrder;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\ImportPuzzleKey;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportSourceKey;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * docs/features/data-import/columns.md "What a parsed row holds": typed values, problems per field, what the row
 * is, its keys. Every row here is made up; today is 14 October 2026.
 */
final class ImportRowParserTest extends TestCase
{
    public function testAResultRowBecomesTypedValuesAndTheKeysOfStage4a(): void
    {
        $row = $this->parse('2021', [
            1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'With', 'First try', 'No box?', 'Entered on MSP?', 'EAN', 'Notes'],
            2 => ['  Paper   Boats ', 'Ravensburger', '1,000', Kit::date('2021-01-30'), Kit::duration(3723), 'Ana & Ben', 'yes', false, 'x', 8590000000015, 'a rainy day'],
        ])[2];

        $puzzleKey = ImportPuzzleKey::of('Paper Boats', 1000, 'Ravensburger');
        self::assertNotNull($puzzleKey);
        $resultKey = ImportSourceKey::result($puzzleKey, new DateTimeImmutable('2021-01-30'), DatePrecision::Day, 3723, PuzzlingType::Team);

        self::assertSame(DataImportRowKind::Result, $row->kind);
        self::assertSame([], $row->problems);
        self::assertSame([$puzzleKey, $resultKey], [$row->puzzleKey, $row->sourceKey]);
        self::assertSame(['puzzle' => $puzzleKey, 'result' => $resultKey], $row->parsed['keys']);
        self::assertSame(
            [
                'name' => 'Paper Boats', 'brand' => 'Ravensburger', 'pieces' => 1000, 'barcodes' => ['8590000000015'], 'date' => '2021-01-30',
                'datePrecision' => 'day', 'seconds' => 3723, 'type' => 'team', 'people' => ['Ana', 'Ben'], 'firstTry' => true, 'withoutBox' => false,
                'alreadyOnMsp' => true, 'custom' => ['private_notes' => 'a rainy day'],
            ],
            array_diff_key($row->parsed, ['keys' => 1, 'content' => 1]),
        );
    }

    public function testWhatIsUnclearIsAProblemOfItsFieldNeverAGuess(): void
    {
        $rows = $this->parse('2020', [
            1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'Solo/pair/team', 'Attempt', 'EAN'],
            2 => ['Glass Garden', 'Heye', '13x99', Kit::date('2020-04-21'), '1:13:??', 'trio', 'second', '4.00556E+12'],
            3 => ['Copper Kettles', 'Jumbo', '?', Kit::date('2028-06-30'), '1:40:11 with a break', 'pair', 3, ''],
            4 => ['Thimble Shop', 'Jumbo', 5, 'last summer', '6:07:01, 6:23:02', 'solo', 1, 'n/a'],
            5 => ['Lemon Grove', 'Heye', 1000, Kit::date('2020-01-18'), Kit::duration(4391), 'solo', 1, 8590000000015],
            6 => ['Night Ferry', 'Heye', 1000, Kit::date('2020-02-09'), Kit::duration(3907), 'solo', 1, 8590000000022],
            7 => ['Sugar Mill', 'Heye', 1000, Kit::date('2020-03-01'), Kit::duration(4127), 'solo', 2, 8590000000039],
            8 => ['Mossy Steps', 'Heye', 1000, Kit::date('2020-03-08'), Kit::duration(4410), 'solo', 1, ''],
        ]);

        self::assertSame(
            [
                ['field' => 'pieces', 'code' => 'pieces_multipack', 'value' => '13x99', 'choices' => [13, 99]],
                ['field' => 'time', 'code' => 'time_unreadable', 'value' => '1:13:??'],
                ['field' => 'solving_type', 'code' => 'type_unknown', 'value' => 'trio'],
                ['field' => 'attempt_number', 'code' => 'attempt_unreadable', 'value' => 'second'],
                ['field' => 'barcode', 'code' => 'barcode_scientific_notation', 'value' => '4.00556E+12'],
            ],
            $rows[2]->problems,
        );
        // Nothing is taken from an unclear value: no piece count in the key, no time
        self::assertArrayNotHasKey('pieces', $rows[2]->parsed);
        self::assertArrayNotHasKey('seconds', $rows[2]->parsed);
        self::assertSame(ImportPuzzleKey::of('Glass Garden', null, 'Heye'), $rows[2]->puzzleKey);

        self::assertSame(
            [
                ['field' => 'pieces', 'code' => 'pieces_unknown', 'value' => '?'],
                ['field' => 'solved_on', 'code' => 'date_in_the_future', 'value' => '2028-06-30'],
                // A time with words around it: proposed, the words go to the notes - and it needs a yes
                ['field' => 'time', 'code' => 'time_needs_yes', 'value' => '1:40:11 with a break'],
            ],
            $rows[3]->problems,
        );
        self::assertSame([6011, ['with a break'], 'duo', 3, false], [$rows[3]->parsed['seconds'], $rows[3]->parsed['notes'], $rows[3]->parsed['type'], $rows[3]->parsed['attempt'], $rows[3]->parsed['firstTry']]);

        self::assertSame(
            [
                ['field' => 'pieces', 'code' => 'pieces_possibly_thousands', 'value' => '5', 'choices' => [5, 5000]],
                ['field' => 'solved_on', 'code' => 'date_unreadable', 'value' => 'last summer'],
                ['field' => 'time', 'code' => 'time_unreadable', 'value' => '6:07:01, 6:23:02'],
            ],
            $rows[4]->problems,
        );
        // Attempt number 1 is a first try (D15); what is no barcode is kept beside
        self::assertSame([true, ['n/a']], [$rows[4]->parsed['firstTry'], $rows[4]->parsed['otherCodes']]);
    }

    public function testFirstTryIsOffUnlessAColumnSaysSo(): void
    {
        $rows = $this->parse('2022', [
            1 => ['Puzzle', 'Pieces', 'Time'],
            2 => ['Attic Window', 1000, Kit::duration(4011)],
        ]);

        self::assertArrayNotHasKey('firstTry', $rows[2]->parsed);
    }

    public function testTheSheetsOptionsReadWhatAValueLeavesOpen(): void
    {
        $kit = new Kit();
        $sheet = Kit::sheet('Results', [
            1 => ['Puzzle', 'Pieces', 'Date', 'Time'],
            2 => ['Plum Orchard', 500, '3/4/2023', '45:10'],
            3 => ['Chalk Cliffs', 500, '20-Sep', '52:31'],
            4 => ['Tin Robots', 500, '30/4/2023', 237],
        ]);
        $mapping = $kit->analyser->analyse($sheet)->mapping;
        $parse = static fn (int $number, ImportSheetOptions $options): ParsedImportRow => $kit->parser->parse($number, $sheet->rows[$number], $mapping, $options);

        // Nothing settled: nothing is read
        $open = $parse(2, new ImportSheetOptions());
        self::assertSame(['date_order_open', 'time_two_readings'], array_column($open->problems, 'code'));
        self::assertSame(['minutes_seconds', 'hours_minutes'], $open->problems[1]['choices'] ?? null);

        $options = (new ImportSheetOptions())
            ->withDateOrder(DateOrder::MonthDayYear, ImportSheetOptions::BY_PLAYER)
            ->withDuration(DurationShape::TwoPartText, DurationReadingRule::MinutesSeconds, ImportSheetOptions::BY_PLAYER)
            ->withDuration(DurationShape::BareNumber, DurationReadingRule::Minutes, ImportSheetOptions::BY_HEADER);

        self::assertSame(['2023-03-04', 2710, []], [$parse(2, $options)->parsed['date'], $parse(2, $options)->parsed['seconds'], $parse(2, $options)->problems]);
        // A date without a year, and no year for the sheet: a year nobody saw is never used
        self::assertSame([['field' => 'solved_on', 'code' => 'date_no_year', 'value' => '20-Sep']], $parse(3, $options)->problems);
        self::assertSame('2021-09-20', $parse(3, $options->withYear(2021, ImportSheetOptions::BY_PLAYER))->parsed['date']);
        // 30/4 can not be month first: taken as it can only be read, and shown
        self::assertSame(['2023-04-30', [['field' => 'solved_on', 'code' => 'date_other_order', 'value' => '30/4/2023']]], [$parse(4, $options)->parsed['date'], $parse(4, $options)->problems]);
        self::assertSame(237 * 60, $parse(4, $options)->parsed['seconds']);
    }

    public function testADateCellThatHidesItsYearTakesTheStoredYearOnlyWhenThePlayerSaidSo(): void
    {
        $kit = new Kit();
        $sheet = Kit::sheet('Results', [1 => ['Puzzle', 'Pieces', 'Date'], 2 => ['Sleepy Tram', 1000, Kit::date('2020-01-05', false)]]);
        $mapping = $kit->analyser->analyse($sheet)->mapping;

        self::assertSame('date_no_year', $kit->parser->parse(2, $sheet->rows[2], $mapping, new ImportSheetOptions())->problems[0]['code']);
        self::assertSame('2020-01-05', $kit->parser->parse(2, $sheet->rows[2], $mapping, (new ImportSheetOptions())->withYear('stored', ImportSheetOptions::BY_PLAYER))->parsed['date']);
        self::assertSame('2019-01-05', $kit->parser->parse(2, $sheet->rows[2], $mapping, (new ImportSheetOptions())->withYear(2019, ImportSheetOptions::BY_PLAYER))->parsed['date']);
    }

    public function testARowOfAListIsOwnedSolvedOrBothAndWhatWentIsNotThePlayers(): void
    {
        $rows = $this->parse('Puzzles', [
            1 => ['Title', 'Brand', 'Pieces', 'Completed?', 'Got rid of'],
            2 => ['Paper Moon Café', 'Ravensburger', 1000, 'Y', ''],
            3 => ['Tin Soldiers', 'Jumbo', 500, 'Y - 30/12/2023', ''],
            4 => ['Lighthouse Keeper', 'Heye', 1000, '', ''],
            5 => ['Orange Trams', 'Trefl', 1500, 'Y', 'yes'],
            6 => ['Wool Shop', 'Eurographics', 1000, 'N', 'x'],
            7 => ['Moth Collection', 'Pomegranate', 1000, 'half done', ''],
        ]);

        self::assertSame(
            [2 => 'both', 3 => 'both', 4 => 'owned', 5 => 'result', 6 => 'not_a_puzzle', 7 => 'owned'],
            array_map(static fn (ParsedImportRow $row): string => $row->kind->value, array_slice($rows, 1, null, true)),
        );
        // A tick with a date in one cell: solved, and when
        self::assertSame(['2023-12-30', 'day'], [$rows[3]->parsed['date'], $rows[3]->parsed['datePrecision']]);
        // A result and an owned puzzle: two keys, the result's first
        $puzzleKey = (string) $rows[3]->puzzleKey;
        self::assertSame(
            ['puzzle' => $puzzleKey, 'result' => ImportSourceKey::result($puzzleKey, new DateTimeImmutable('2023-12-30'), DatePrecision::Day, null, PuzzlingType::Solo), 'owned' => ImportSourceKey::owned($puzzleKey)],
            $rows[3]->parsed['keys'],
        );
        self::assertSame($rows[3]->parsed['keys']['result'], $rows[3]->sourceKey);
        self::assertSame(ImportSourceKey::owned((string) $rows[4]->puzzleKey), $rows[4]->sourceKey);
        self::assertSame('not_mine', $rows[6]->parsed['leftOut']);
        self::assertSame([['field' => 'status', 'code' => 'status_unknown', 'value' => 'half done']], $rows[7]->problems);
    }

    public function testTheSameCellsUnderTheSameMappingAlwaysGiveTheSameRow(): void
    {
        $first = (new Kit())->workbook(ImportShapes::trainingWorkbook());
        $second = (new Kit())->workbook(array_reverse(ImportShapes::trainingWorkbook()));

        foreach ($first as $title => $sheet) {
            self::assertEquals(
                array_map(static fn (ParsedImportRow $row): array => $row->toRecord(), $sheet['rows']),
                array_map(static fn (ParsedImportRow $row): array => $row->toRecord(), $second[$title]['rows']),
                $title,
            );
        }
    }

    public function testNoValueMakesTheParserThrow(): void
    {
        $kit = new Kit();
        $odd = ["\xff\xfe broken", str_repeat('x', 5000), '=1+1', '🧩', "line\nbreak", '-1:02:03', '1e99', 'NaN', '０：５６：４', ' ', '#N/A'];
        $rows = [1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'With', 'First try', 'EAN', 'Attempt', 'Status']];

        foreach ($odd as $index => $value) {
            $rows[$index + 2] = array_fill(0, 10, $value);
        }

        $rows[] = [['k' => 'error', 'v' => '#DIV/0!'], ['k' => 'number', 'v' => NAN], ['k' => 'date', 'v' => 'not a date'], ['k' => 'duration'], ['k' => 'nothing we know', 'v' => []]];
        $sheet = Kit::sheet('Odd', $rows);
        $mapping = $kit->analyser->analyse($sheet)->mapping;

        $parsed = 0;

        foreach ($sheet->rows as $number => $cells) {
            $kit->parser->parse($number, $cells, $mapping, new ImportSheetOptions());
            $parsed++;
        }

        self::assertSame(13, $parsed);
    }

    /**
     * @param array<int, list<mixed>> $rows
     *
     * @return array<int, ParsedImportRow>
     */
    private function parse(string $title, array $rows): array
    {
        $sheet = (new Kit())->workbook([Kit::sheet($title, $rows)])[$title];
        self::assertSame([], $sheet['proposal']->mapping->questions);

        return $sheet['rows'];
    }
}
