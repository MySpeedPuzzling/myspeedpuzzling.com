<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\DataImport;

use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\PuzzlerDataExporter;
use SpeedPuzzling\Web\Tests\Services\DataImport\ImportTestKit as Kit;
use SpeedPuzzling\Web\Value\DataImport\ColumnCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportColumnRole;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\KnownExportLayout;
use SpeedPuzzling\Web\Value\DataImport\MappedColumn;
use SpeedPuzzling\Web\Value\DataImport\SheetSample;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\ExportFormat;
use SpeedPuzzling\Web\Value\Spreadsheet\SpreadsheetFormat;
use SpeedPuzzling\Web\Value\StandardCustomField;

/**
 * docs/features/data-import/columns.md "Known exports": Puzzle Tracker's CSV and our own results export are
 * recognised by their headers - nothing to map, nothing to ask. Every row here is made up.
 */
final class KnownExportLayoutTest extends TestCase
{
    public function testPuzzleTrackersExportIsRecognisedWithEveryColumnCertain(): void
    {
        $sheet = (new Kit())->workbook(ImportShapes::puzzleTrackerExport())[''];
        $mapping = $sheet['proposal']->mapping;

        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, $mapping->layout);
        self::assertSame([], $mapping->questions);
        self::assertCount(23, $mapping->columns);

        foreach ($mapping->columns as $column) {
            self::assertSame([ColumnCertainty::Certain, MappedColumn::BY_KNOWN_EXPORT], [$column->certainty, $column->reason], $column->header);
        }

        $byHeader = [];

        foreach ($mapping->columns as $column) {
            $byHeader[$column->header] = match (true) {
                $column->role !== null => $column->role->value,
                $column->readyMade !== null => $column->readyMade->value,
                default => $column->field?->value,
            };
        }

        self::assertSame(
            [
                'ID' => 'source_row_id', 'Name' => 'puzzle_name', 'Brand' => 'brand', 'Number of elements' => 'pieces', 'Status' => 'status', 'Time' => 'time',
                'Creation date' => null, 'Start date' => 'started_on', 'End date' => 'solved_on', 'Satisfaction' => 'how_i_liked_it', 'Difficulty' => 'how_hard_it_felt',
                'Quality' => 'quality', 'Price' => 'price_paid', 'Sell price' => null, 'Missing pieces' => 'missing_pieces', 'Place of purchase' => 'bought_from',
                'Artist' => 'artist', 'Puzzle type' => 'condition', 'Bar code' => 'barcode', 'Tags' => 'my_tags', 'Notes' => 'private_notes', 'Picture id' => null,
                'Picture URL' => 'picture_url',
            ],
            $byHeader,
        );
    }

    public function testPuzzleTrackersStatusIdsAndHashtagsAreRead(): void
    {
        $rows = (new Kit())->workbook(ImportShapes::puzzleTrackerExport())['']['rows'];

        // The status decides what a row is - the wish list included; a tag says a completed puzzle went
        self::assertSame(
            [2 => 'both', 3 => 'both', 4 => 'result', 5 => 'both', 6 => 'owned', 7 => 'owned', 8 => 'wish_list', 9 => 'result'],
            array_map(static fn ($row): string => $row->kind->value, array_slice($rows, 1, null, true)),
        );
        // The app's own id is the row's identity
        self::assertSame('0d6e1c5a-0000-4000-8000-000000000001', $rows[2]->sourceRowId);
        // Times without leading zeros, dates as dd.mm.yyyy - day first by the layout's word, not by a question
        self::assertSame(['2024-02-03', 3364], [$rows[2]->parsed['date'], $rows[2]->parsed['seconds']]);
        self::assertSame(['2024-02-11', 3909], [$rows[3]->parsed['date'], $rows[3]->parsed['seconds']]);
        // #01-individual / #02-pair / #03-team
        self::assertSame(['solo', 'duo', 'team'], [$rows[2]->parsed['type'], $rows[3]->parsed['type'], $rows[4]->parsed['type']]);
        // The tags, the ratings and the picture stay with the row
        self::assertEquals(
            ['started_on' => '04.03.2024', 'how_i_liked_it' => '4', 'how_hard_it_felt' => '3', 'quality' => '5', 'condition' => 'Brand new', 'my_tags' => '#03-team #xSOLD', 'private_notes' => 'Team - 3'],
            $rows[4]->parsed['custom'],
        );
        self::assertSame('https://pictures.example/01.jpg', $rows[2]->parsed['pictureUrl']);
        self::assertSame(['8590000000015'], $rows[2]->parsed['barcodes']);
        // A row of the app is a row, even without brand and pieces
        self::assertSame(DataImportRowKind::Owned, $rows[7]->kind);
        // Each solve of a puzzle is its own row: one puzzle, two results
        self::assertSame($rows[2]->puzzleKey, $rows[5]->puzzleKey);
        self::assertNotSame($rows[2]->sourceKey, $rows[5]->sourceKey);

        foreach ($rows as $row) {
            self::assertSame([], $row->problems);
        }
    }

    public function testAKnownExportWithAHeaderTwiceKeepsOneColumnPerField(): void
    {
        $sheet = ImportShapes::puzzleTrackerExport()[0];
        $rows = $sheet->rows;
        // Somebody added a second "Notes" and a second "ID" column to the export
        $rows[1][24] = Kit::text('Notes');
        $rows[2][24] = Kit::text('more notes');
        $rows[1][25] = Kit::text('ID');
        $rows[2][25] = Kit::text('another id');

        $mapping = (new Kit())->analyser->analyse(new SheetSample('', $rows, null, count($rows)))->mapping;

        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, $mapping->layout);
        self::assertSame([null, null, ColumnCertainty::Unknown, MappedColumn::BY_DUPLICATE], [$mapping->columns[23]->field, $mapping->columns[23]->role, $mapping->columns[23]->certainty, $mapping->columns[23]->reason]);
        self::assertSame([null, null, MappedColumn::BY_DUPLICATE], [$mapping->columns[24]->field, $mapping->columns[24]->role, $mapping->columns[24]->reason]);
        self::assertSame(ImportColumnRole::SourceRowId, $mapping->columns[0]->role);
        // ... and so it is a mapping that can be stored
        self::assertSame($mapping->toArray(), ImportMapping::fromArray($mapping->toArray())->toArray());
    }

    public function testAFileThatLacksOneOfTheIdentifyingHeadersIsNobodysExport(): void
    {
        $headers = ['ID', 'Name', 'Brand', 'Number of elements', 'Status', 'Time', 'Start date', 'End date', 'Bar code', 'Tags'];

        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, KnownExportLayout::matching($headers)?->id);
        self::assertSame(KnownExportLayout::PUZZLE_TRACKER, KnownExportLayout::matching(array_map(strtoupper(...), array_reverse($headers)))?->id);
        self::assertNull(KnownExportLayout::matching(array_diff($headers, ['Status'])));
        self::assertNull(KnownExportLayout::matching(['Puzzle', 'Brand', 'Pieces', 'Date', 'Time']));
    }

    #[DataProvider('provideFormatsOfOurExport')]
    public function testOurOwnResultsExportComesBackWithoutAQuestion(ExportFormat $format, SpreadsheetFormat $read): void
    {
        $content = (new PuzzlerDataExporter())->export([
            self::exported('018f0000-0000-7000-8000-000000000001', 'Invented Harbour at Dawn', 3723, 'solo', firstAttempt: true, comment: '=not a formula'),
            self::exported('018f0000-0000-7000-8000-000000000002', 'Made-up Meadow', 45, 'duo', teamMembers: 'Ana, Ben'),
            self::exported('018f0000-0000-7000-8000-000000000003', 'Imaginary Lighthouse', null, 'team', teamMembers: 'Ana, Ben, Cy', unboxed: true),
        ], $format);

        $kit = new Kit();
        $sheet = (new SpreadsheetFile())->read($content, $read);
        $parsed = $kit->workbook([$sheet])[$sheet->title];
        $mapping = $parsed['proposal']->mapping;

        self::assertSame(KnownExportLayout::MYSPEEDPUZZLING_RESULTS, $mapping->layout);
        self::assertSame([], $mapping->questions);
        self::assertSame([3, 0, 1], [$parsed['understood'], $parsed['needLook'], $parsed['leftOut']]);
        self::assertSame(ImportColumnRole::SourceRowId, $mapping->columns[0]->role);
        self::assertSame(ImportColumnRole::PuzzleId, $mapping->columns[1]->role);
        self::assertSame(ImportField::Computed, $mapping->column(ImportField::Computed)?->field);

        foreach ($mapping->columns as $column) {
            self::assertSame(ColumnCertainty::Certain, $column->certainty, $column->header);
        }

        $rows = array_values(array_filter($parsed['rows'], static fn ($row): bool => $row->isData()));

        self::assertSame('018f0000-0000-7000-8000-000000000001', $rows[0]->sourceRowId);
        self::assertSame(
            ['name' => 'Invented Harbour at Dawn', 'brand' => 'Made-up Brand', 'pieces' => 500, 'puzzleId' => '018f0000-0000-7000-8000-0000000000aa', 'date' => '2025-03-02', 'seconds' => 3723, 'type' => 'solo', 'firstTry' => true, 'comment' => '=not a formula'],
            array_intersect_key($rows[0]->parsed, array_flip(['name', 'brand', 'pieces', 'puzzleId', 'date', 'seconds', 'type', 'firstTry', 'comment'])),
        );
        // Seconds are seconds by the layout's word - 45 is never asked about
        self::assertSame([45, 'duo', ['Ana', 'Ben']], [$rows[1]->parsed['seconds'], $rows[1]->parsed['type'], $rows[1]->parsed['people']]);
        // A result without a time
        self::assertArrayNotHasKey('seconds', $rows[2]->parsed);
        self::assertSame(['team', true], [$rows[2]->parsed['type'], $rows[2]->parsed['withoutBox']]);
    }

    /**
     * @return iterable<string, array{ExportFormat, SpreadsheetFormat}>
     */
    public static function provideFormatsOfOurExport(): iterable
    {
        yield 'csv' => [ExportFormat::Csv, SpreadsheetFormat::Csv];
        yield 'xlsx' => [ExportFormat::Xlsx, SpreadsheetFormat::Xlsx];
    }

    public function testAReadyMadeFieldOfOurExportIsKnownByItsKey(): void
    {
        $layout = KnownExportLayout::byId(KnownExportLayout::MYSPEEDPUZZLING_RESULTS);
        self::assertNotNull($layout);

        self::assertSame(StandardCustomField::PrivateNotes, $layout->column(31, 'custom:private_notes')->readyMade);
        // The player's own field: nothing we know - offered as a new field by the next stage's screen
        self::assertNull($layout->column(32, 'custom:own:Shelf')->field);
    }

    private static function exported(string $id, string $name, null|int $seconds, string $type, bool $firstAttempt = false, bool $unboxed = false, string $teamMembers = '', null|string $comment = null): ExportableSolvingTime
    {
        return new ExportableSolvingTime(
            $id,
            '018f0000-0000-7000-8000-0000000000aa',
            $name,
            'Made-up Brand',
            500,
            $seconds,
            $seconds === null ? '' : gmdate('H:i:s', $seconds),
            new DateTimeImmutable('2025-03-02 14:05:09'),
            new DateTimeImmutable('2025-03-02 15:00:00'),
            $type,
            $firstAttempt,
            $unboxed,
            $type === 'solo' ? 1 : ($type === 'duo' ? 2 : 3),
            $teamMembers,
            null,
            $comment,
            3000,
            '00:50:00',
            4000,
            '01:06:40',
            2,
            17,
        );
    }
}
