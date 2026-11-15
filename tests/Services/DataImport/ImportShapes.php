<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;

/**
 * The SHAPES of the four real files the import was designed on (docs/features/data-import/research.md) - with
 * invented puzzles, people and numbers. Nothing here is anybody's data.
 */
final class ImportShapes
{
    /**
     * Shape 1: a workbook of ten sheets - a to-do pile, a keep pile and a sheet per year of results whose headers
     * drift from year to year; dates typed as m/d (the year hidden), times as cells and as text, a summary block
     * under the data.
     *
     * @return list<SheetSample>
     */
    public static function tenSheetWorkbook(): array
    {
        $hidden = static fn (string $date): array => Kit::date($date, false);
        $summary = static fn (int $after): array => [
            $after + 2 => ['Total', '', Kit::formula(Kit::number(4500))],
            $after + 3 => ['Average', '', Kit::formula(Kit::number(900)), '', Kit::formula(Kit::duration(4011))],
        ];

        return [
            Kit::sheet('To Do', [
                1 => ['Title', 'Brand', 'Pieces', 'Artist', 'Purchased', 'Price'],
                2 => ['Invented Harbour at Dawn', 'Ravensburger', 1000, 'A. Painter', Kit::date('2023-05-01'), 12.5],
                3 => ['Made-up Meadow', 'MB', '500'],
                4 => ['Imaginary Lighthouse (mini)', 'Cobble Hill', '13x99'],
                5 => ['', 'Buffalo', 2000],
                6 => ['Paper Lantern Street', 'Trefl', '?'],
                7 => ['Clockwork Orchard', 'Schmidt', 1500, 'B. Drawer'],
                9 => ['Total', '', Kit::formula(Kit::number(5000))],
                10 => ['bought mostly second-hand'],
            ]),
            Kit::sheet('Keep', [
                1 => ['Title', 'Brand', 'Pieces', 'Got rid of', 'Where it is'],
                2 => ['Tin Robot Parade', 'Galison', 500, '', 'Shelf 2'],
                3 => ['Quiet Canal', 'Heye', 1000, 'yes', ''],
                4 => ['Paper Kites', 'Eurographics', 1000, 'Back to the library', ''],
                5 => ['Winter Bakery', 'Ravensburger', 1000, 'x', ''],
                6 => ['Seven Lanterns', 'Pomegranate', 1000, '', 'Shelf 1'],
            ]),
            // The year hidden by the cells' format - and the cells store another year than the sheet's name says
            Kit::sheet('2019', [
                1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time'],
                2 => ['Sleepy Tram', 'Trefl', 1000, $hidden('2020-01-05'), Kit::duration(4157)],
                3 => ['Marble Staircase', 'Clementoni', 1000, $hidden('2020-02-11'), Kit::duration(5321)],
                4 => ['Red Umbrellas', 'Ravensburger', 500, $hidden('2020-03-02'), Kit::duration(2203)],
            ]),
            Kit::sheet('2020', [
                1 => ['Title', 'Manufacturer', '# of pieces', 'Completed', 'Time taken', 'Comments'],
                2 => ['Lemon Grove', 'Ravensburger', 1000, $hidden('2020-01-18'), Kit::duration(4391), 'with tea'],
                3 => ['Night Ferry', 'Schmidt', 1000, $hidden('2020-02-09'), '8min', ''],
                4 => ['Glass Garden', 'Heye', 1000, $hidden('2020-04-21'), '1:13:??', ''],
                5 => ['Copper Kettles', 'Jumbo', 500, $hidden('2020-06-30'), '1h 23m', 'borrowed from Ina'],
                6 => ['Thimble Shop', 'Jumbo', 500, $hidden('2020-09-14'), Kit::duration(2988), ''],
                ...$summary(6),
            ]),
            Kit::sheet('2021', [
                1 => ['Name', 'Brand', 'Pcs', 'Finished', 'Duration', 'With'],
                2 => ['Paper Boats', 'Ravensburger', 1000, '30/01/2021', Kit::duration(3723), 'Ana'],
                3 => ['Velvet Curtains', 'Castorland', 1500, '14/02/2021', Kit::duration(7411), ''],
                4 => ['Button Jar', 'Galison', 500, '03/04/2021', Kit::duration(1999), 'Ana, Ben'],
                5 => ['Honey Market', 'Trefl', 1000, 'Sept 2021', Kit::duration(4444), ''],
                ...$summary(5),
            ]),
            Kit::sheet('2022', [
                1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'PPM'],
                2 => ['Attic Window', 'Ravensburger', 1000, $hidden('2022-01-03'), Kit::duration(4011), Kit::formula(Kit::number(14.96))],
                3 => ['Fig Tree Lane', 'Cobble Hill', 1000, $hidden('2022-03-19'), Kit::duration(4699), Kit::formula(Kit::number(12.77))],
                4 => ['Blue Teapots', 'Galison', 500, $hidden('2022-05-08'), '0:55:41 (slower than last time)', Kit::formula(Kit::number(8.98))],
                5 => ['Stone Bridge', 'Heye', 2000, $hidden('2022-08-27'), Kit::duration(18042), Kit::formula(Kit::number(6.65))],
                ...$summary(5),
            ]),
            // Times typed as "17:36": minutes and seconds, or hours and minutes - nothing here settles it
            Kit::sheet('2023', [
                1 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time'],
                2 => ['Plum Orchard', 'Ravensburger', 500, $hidden('2023-02-02'), '45:10'],
                3 => ['Chalk Cliffs', 'Trefl', 500, $hidden('2023-04-16'), '52:31'],
                4 => ['Yellow Bicycles', 'Schmidt', 1000, $hidden('2023-07-07'), '40:05'],
                5 => ['Harbour Lights', 'Jumbo', 1000, $hidden('2023-11-23'), '38:12'],
            ]),
            Kit::sheet('2024', [
                1 => ['Puzzle name', 'Maker', 'Piece count', 'Date finished', 'Solve time', 'Notes'],
                2 => ['Lantern Festival', 'Ravensburger', 1000, Kit::date('2024-01-06'), Kit::duration(3907), ''],
                3 => ['Mossy Steps', 'Pomegranate', 1000, Kit::date('2028-03-03'), Kit::duration(4410), 'typo in the year'],
                4 => ['Sugar Mill', 'Eurographics', 1000, Kit::date('2024-05-25'), Kit::duration(4127), ''],
                5 => ['Tidy Workshop', 'Castorland', 500, Kit::date('2024-09-09'), Kit::duration(1811), ''],
                ...$summary(5),
            ]),
            Kit::sheet('2025', [
                1 => ['Název', 'Výrobce', 'Počet dílků', 'Datum', 'Čas', 'Poznámka'],
                2 => ['Tichá zátoka', 'Dino', 1000, '18.01.2025', Kit::duration(4250), ''],
                3 => ['Perníková ulička', 'Trefl', 500, '02.03.2025', Kit::duration(2105), 's Evou'],
                4 => ['Mlýn u potoka', 'Ravensburger', 1000, '27.12.2025', Kit::duration(3880), ''],
            ]),
            Kit::sheet('2026', [
                1 => ['Titel', 'Hersteller', 'Teile', 'Datum', 'Zeit', 'Bemerkung'],
                2 => ['Stiller Hafen', 'Schmidt', 1000, '11.01.2026', Kit::duration(4172), ''],
                3 => ['Bunte Drachen', 'Ravensburger', 1000, '23.02.2026', Kit::duration(3655), ''],
                4 => ['Alte Mühle', 'Heye', 2000, '05.07.2026', Kit::duration(16901), 'mit Jonas angefangen'],
            ]),
        ];
    }

    /**
     * Shape 2: one sheet, every cell a formula (a Google Sheets export built with IMPORTRANGE), no time column at
     * all; "Completed?" as `Y` or `Y - dd/mm/yyyy`; a piece count as `12*80`.
     *
     * @return list<SheetSample>
     */
    public static function allFormulasExport(): array
    {
        $rows = [
            1 => ['Title', 'Brand', 'Pieces', 'Completed?', 'Artist', 'Where'],
            2 => ['Paper Moon Café', 'Ravensburger', 1000, 'Y', 'C. Sketch', 'Shelf A'],
            3 => ['Tin Soldiers', 'Jumbo', 500, 'Y - 30/12/2023', '', 'Shelf A'],
            4 => ['Lighthouse Keeper', 'Heye', 1000, '', 'D. Brush', 'Box 3'],
            5 => ['Patchwork Hills', 'Galison', '12*80', '', '', 'Box 3'],
            6 => ['Orange Trams', 'Trefl', 1500, 'Y - 14/02/2024', '', ''],
            7 => ['', 'Castorland', 2000, '', '', 'Attic'],
            8 => ['Wool Shop', 'Eurographics', 1000, 'Y', '', ''],
            9 => ['Moth Collection', 'Pomegranate', 1000, '', 'E. Ink', ''],
        ];

        $sheet = Kit::sheet('Puzzles', $rows);

        return [new SheetSample(
            $sheet->title,
            array_map(static fn (array $cells): array => array_map(Kit::formula(...), $cells), $sheet->rows),
            null,
            $sheet->rowsTotal,
        )];
    }

    /**
     * Shape 3: a training workbook - every sheet an Excel table under a dashboard of formulas; a puzzle list;
     * results sheets that name the puzzle only (brand and pieces are on the list); tables stretched down with
     * unticked checkboxes; template rows; twenty small tables side by side; statistics and a brand list.
     *
     * @return list<SheetSample>
     */
    public static function trainingWorkbook(): array
    {
        $dashboard = [
            1 => ['Solved', Kit::formula(Kit::number(14)), 'Best', Kit::formula(Kit::duration(2411))],
            2 => ['Average', Kit::formula(Kit::duration(2893)), 'PPM', Kit::formula(Kit::number(10.4))],
            4 => ['Goal', Kit::formula(Kit::duration(2400))],
        ];
        $table = static fn (string $name, string $range): array => ['tables' => [['name' => $name, 'range' => $range, 'headerRow' => 6, 'totalsRows' => 0]], 'frozenPane' => ['row' => 7, 'column' => 1]];
        $unticked = static function (int $from, int $to, int $columns): array {
            $rows = [];

            for ($row = $from; $row <= $to; $row++) {
                $rows[$row] = [...array_fill(0, $columns - 1, null), false];
            }

            return $rows;
        };

        $drills = [];
        $drillTables = [];

        for ($table20 = 0; $table20 < 20; $table20++) {
            $first = 1 + $table20 * 4;
            $drillTables[] = ['name' => 'Drill' . ($table20 + 1), 'range' => self::a1($first, 6) . ':' . self::a1($first + 2, 9), 'headerRow' => 6, 'totalsRows' => 0];

            foreach ([6 => ['Date', 'Time', 'Sorted'], 7 => [Kit::date('2026-03-0' . (1 + $table20 % 9)), Kit::duration(301 + $table20), 120], 8 => [Kit::date('2026-04-1' . ($table20 % 9)), Kit::duration(287 + $table20), 126]] as $row => $values) {
                $drills[$row] = ($drills[$row] ?? []) + Kit::row($values, $first);
            }
        }

        return [
            Kit::sheet('Puzzle List', $dashboard + [
                6 => ['Puzzle', 'Brand', 'Pieces', 'Tags'],
                7 => ['Circle of Kites', 'Ravensburger', 500, 'Photo, Animals'],
                8 => ['Marble Run', 'Ravensburger', 500, 'Collage'],
                9 => ['Tea House', 'Trefl', 500, 'Painting'],
                10 => ['Glass Marbles', 'Jumbo', 500, 'Photo'],
                11 => ['Twin Bridges', 'Schmidt', 500, ''],
                12 => ['Twin Bridges', 'Schmidt', 1000, ''],
                13 => ['Candy Jars', 'Galison', 300, 'Collage'],
                14 => ['Fox Hollow', 'Cobble Hill', 300, 'Animals'],
                15 => ['Made-up Lighthouse', 'Ravensburger', 100, 'Speed run'],
            ], $table('PuzzleList', 'A6:D15')),
            // No brand and no pieces column: both are on the list. The table is stretched down with unticked boxes
            Kit::sheet('500 Solo', $dashboard + [
                6 => ['Puzzle', 'Date', 'Time', 'Attempt', 'PPM', 'Entered on MSP?'],
                7 => ['Circle of Kites', Kit::date('2026-01-10'), Kit::duration(2893), 1, Kit::formula(Kit::number(10.37)), true],
                8 => ['Circle of Kites', Kit::date('2026-01-17'), Kit::duration(2655), 2, Kit::formula(Kit::number(11.3)), true],
                9 => ['Marble Run', Kit::date('2026-01-24'), Kit::duration(3104), 1, Kit::formula(Kit::number(9.66)), false],
                10 => ['Tea House', Kit::date('2026-02-01'), Kit::duration(2411), 1, Kit::formula(Kit::number(12.44)), true],
                // The same puzzle, day and time twice - a log of attempts keeps them apart
                11 => ['Glass Marbles', Kit::date('2026-02-08'), Kit::duration(2750), 3, Kit::formula(Kit::number(10.91)), true],
                12 => ['Glass Marbles', Kit::date('2026-02-08'), Kit::duration(2750), 4, Kit::formula(Kit::number(10.91)), true],
                // On the list twice, with another piece count: no answer
                13 => ['Twin Bridges', Kit::date('2026-02-15'), Kit::duration(2999), 1, Kit::formula(Kit::number(10.0)), false],
                // A typo in the year
                14 => ['Tea House', Kit::date('2028-02-22'), Kit::duration(2380), 2, Kit::formula(Kit::number(12.6)), false],
                // Not on the list at all
                15 => ['Unlisted Windmill', Kit::date('2026-03-01'), Kit::duration(3333), 1, Kit::formula(Kit::number(9.0)), false],
            ] + $unticked(16, 40, 6), $table('Solo500', 'A6:F40')),
            Kit::sheet('Pairs', $dashboard + [
                6 => ['Puzzle', 'Brand', 'Pieces', 'Date', 'Time', 'Partner', 'Attempt'],
                7 => ['Candy Jars', 'Galison', 300, Kit::date('2026-03-07'), Kit::duration(1022), 'Mira', 1],
                8 => ['Fox Hollow', 'Cobble Hill', 300, Kit::date('2026-03-14'), Kit::duration(1175), 'Mira', 1],
                9 => ['Candy Jars', 'Galison', 300, Kit::date('2026-03-21'), Kit::duration(948), 'Mira', 2],
            ], $table('Pairs', 'A6:G9')),
            // One sheet per speed-run puzzle: rows prefilled with the puzzle, waiting for a date and a time
            Kit::sheet('Speed run', $dashboard + [
                6 => ['Puzzle', 'Date', 'Time', 'Attempt'],
                7 => ['Made-up Lighthouse', Kit::date('2026-04-02'), Kit::duration(412), 1],
                8 => ['Made-up Lighthouse', Kit::date('2026-04-02'), Kit::duration(388), 2],
                9 => ['Made-up Lighthouse', Kit::date('2026-04-03'), Kit::duration(371), 3],
                10 => ['Made-up Lighthouse', null, null, 4],
                11 => ['Made-up Lighthouse', null, null, 5],
                12 => ['Made-up Lighthouse', null, null, 6],
            ], $table('SpeedRun', 'A6:D12')),
            new SheetSample('Drills', $drills, ['tables' => $drillTables], count($drills)),
            Kit::sheet('Stats', [
                1 => ['Month', 'Solved', 'Hours'],
                2 => ['January', Kit::formula(Kit::number(4)), Kit::formula(Kit::number(3.4))],
                3 => ['February', Kit::formula(Kit::number(5)), Kit::formula(Kit::number(4.1))],
                4 => ['March', Kit::formula(Kit::number(3)), Kit::formula(Kit::number(0.9))],
            ]),
            Kit::sheet('Brands', [
                1 => ['Brand', 'Count'],
                2 => ['Ravensburger', Kit::formula(Kit::number(4))],
                3 => ['Trefl', Kit::formula(Kit::number(1))],
                4 => ['Jumbo', Kit::formula(Kit::number(1))],
            ]),
        ];
    }

    /**
     * Shape 4: Puzzle Tracker's CSV export - one header row, 23 columns, an id per row, a status that says what a row
     * is (the wish list included), times without leading zeros, dates as dd.mm.yyyy, the player's own system in
     * hashtags. Every cell of a CSV is a text.
     *
     * @return list<SheetSample>
     */
    public static function puzzleTrackerExport(): array
    {
        $header = ['ID', 'Name', 'Brand', 'Number of elements', 'Status', 'Time', 'Creation date', 'Start date', 'End date', 'Satisfaction', 'Difficulty', 'Quality', 'Price', 'Sell price', 'Missing pieces', 'Place of purchase', 'Artist', 'Puzzle type', 'Bar code', 'Tags', 'Notes', 'Picture id', 'Picture URL'];
        $row = static fn (string $id, string $name, string $brand, string $pieces, string $status, string $time, string $start, string $end, string $barcode, string $tags, string $notes = ''): array => [
            '0d6e1c5a-0000-4000-8000-0000000000' . $id, $name, $brand, $pieces, $status, $time, '01.01.2024', $start, $end,
            $status === 'Completed' ? '4' : '', $status === 'Completed' ? '3' : '', $status === 'Completed' ? '5' : '', '', '', '', '', '',
            'Brand new', $barcode, $tags, $notes, 'pic-' . $id, 'https://pictures.example/' . $id . '.jpg',
        ];

        return [Kit::sheet('', [
            1 => $header,
            2 => $row('01', 'Vymyšlený přístav', 'Ravensburger', '1000', 'Completed', '0:56:4', '03.02.2024', '03.02.2024', '8590000000015', '#01-individual'),
            3 => $row('02', 'Papírové draky', 'Trefl', '500', 'Completed', '1:5:9', '10.02.2024', '11.02.2024', '', '#02-pair', 'with Petra'),
            4 => $row('03', 'Tichá zátoka', 'Dino', '1000', 'Completed', '2:14:33', '04.03.2024', '04.03.2024', '', '#03-team #xSOLD', 'Team - 3'),
            // Solved twice: each solve is its own row
            5 => $row('04', 'Vymyšlený přístav', 'Ravensburger', '1000', 'Completed', '0:49:58', '12.05.2024', '12.05.2024', '8590000000015', '#01-individual'),
            6 => $row('05', 'Mlýn u potoka', 'Ravensburger', '1000', 'Waiting list', '', '', '', '', ''),
            7 => $row('06', 'Zimní pekárna', '', '', 'Waiting list', '', '', '', '', '#Lended_Katka'),
            8 => $row('07', 'Sedm luceren', 'Schmidt', '1500', 'Wish list', '', '', '', '', ''),
            9 => $row('08', 'Stará tramvaj', 'Trefl', '1000', 'Completed', '', '20.06.2024', '21.06.2024', '', '#01-individual #xGIFTED'),
        ])];
    }

    private static function a1(int $column, int $row): string
    {
        return \SpeedPuzzling\Web\Value\Spreadsheet\CellRange::columnLetters($column) . $row;
    }
}
