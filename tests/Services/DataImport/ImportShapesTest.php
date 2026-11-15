<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use PHPUnit\Framework\TestCase;

/**
 * The four shapes of real files (docs/features/data-import/research.md; ImportShapes - invented puzzles) from
 * stored cells to parsed rows, without a column touched by hand: how many rows are understood, how many need a
 * look, which sheets wait for an answer. A change of a recogniser or a reader that moves one of these numbers must
 * be meant.
 */
final class ImportShapesTest extends TestCase
{
    public function testTheTenSheetWorkbookWithDriftingHeaders(): void
    {
        self::assertSame(
            [
                'To Do' => ['owned', 3, 3, 3, []],
                'Keep' => ['owned', 2, 1, 3, []],
                // The cells hide a year and store another than the sheet's name says: asked
                '2019' => ['results', 0, 0, 0, ['year']],
                '2020' => ['results', 4, 1, 3, []],
                '2021' => ['results', 4, 0, 3, []],
                '2022' => ['results', 3, 1, 3, []],
                // "45:10": minutes and seconds, or hours and minutes - asked
                '2023' => ['results', 0, 0, 0, ['duration_shape.two_part_text']],
                '2024' => ['results', 3, 1, 3, []],
                '2025' => ['results', 3, 0, 1, []],
                '2026' => ['results', 3, 0, 1, []],
            ],
            $this->outcome(ImportShapes::tenSheetWorkbook()),
        );
    }

    public function testTheExportOfNothingButFormulasWithoutATimeColumn(): void
    {
        self::assertSame(['Puzzles' => ['owned', 6, 2, 1, []]], $this->outcome(ImportShapes::allFormulasExport()));
    }

    public function testTheTrainingWorkbookOfTablesUnderDashboards(): void
    {
        self::assertSame(
            [
                'Puzzle List' => ['puzzle_list', 9, 0, 4, []],
                // 25 rows of unticked checkboxes, the dashboard and the header are left out
                '500 Solo' => ['results', 7, 2, 29, []],
                'Pairs' => ['results', 3, 0, 4, []],
                // 3 template rows left out
                'Speed run' => ['results', 3, 0, 7, []],
                'Drills' => ['not_puzzles', 0, 0, 0, []],
                'Stats' => ['not_puzzles', 0, 0, 0, []],
                'Brands' => ['not_puzzles', 0, 0, 0, []],
            ],
            $this->outcome(ImportShapes::trainingWorkbook()),
        );
    }

    public function testPuzzleTrackersCsvExport(): void
    {
        self::assertSame(['' => ['results', 8, 0, 1, []]], $this->outcome(ImportShapes::puzzleTrackerExport()));
    }

    public function testBrandAndPiecesOfTheTrainingWorkbooksResultsComeFromItsPuzzleList(): void
    {
        $rows = (new ImportTestKit())->workbook(ImportShapes::trainingWorkbook())['500 Solo']['rows'];

        self::assertSame(['Ravensburger', 500, ['brand' => 'lookup', 'pieces' => 'lookup']], [$rows[7]->parsed['brand'], $rows[7]->parsed['pieces'], $rows[7]->parsed['from']]);
        // On the list twice with another piece count: a problem of the row, nothing taken
        self::assertSame([['field' => 'puzzle_name', 'code' => 'lookup_ambiguous', 'value' => 'Twin Bridges']], $rows[13]->problems);
        self::assertArrayNotHasKey('pieces', $rows[13]->parsed);
        // Not on the list: still a result, of a puzzle known by its name only
        self::assertSame([[], false], [$rows[15]->problems, isset($rows[15]->parsed['brand'])]);
        // Equal in puzzle, day and time - the same key until the sheet's equal rows are numbered
        self::assertSame($rows[11]->sourceKey, $rows[12]->sourceKey);
        self::assertSame([3, 4], [$rows[11]->parsed['attempt'], $rows[12]->parsed['attempt']]);
    }

    /**
     * @param list<\SpeedPuzzling\Web\Value\DataImport\SheetSample> $sheets
     *
     * @return array<array-key, array{string, int, int, int, list<string>}> sheet => kind, understood, need a look, left out, questions
     */
    private function outcome(array $sheets): array
    {
        return array_map(
            static fn (array $sheet): array => [
                $sheet['proposal']->mapping->kind->value,
                $sheet['understood'],
                $sheet['needLook'],
                $sheet['leftOut'],
                array_map(static fn ($question): string => $question->id(), $sheet['proposal']->mapping->questions),
            ],
            (new ImportTestKit())->workbook($sheets),
        );
    }
}
