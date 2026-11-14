<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Export;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\Export\ExportDocument;
use SpeedPuzzling\Web\Results\Export\ExportSection;
use SpeedPuzzling\Web\Results\Export\OwnCustomColumns;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\Export\SectionedExportWriter;
use SpeedPuzzling\Web\Services\PuzzlerDataExporter;
use SpeedPuzzling\Web\Value\ExportFormat;
use ZipArchive;

/**
 * The player's own fields in their exports (docs/features/data-export.md "Own fields"): extra columns after every
 * other one in the spreadsheet formats, an object `custom` in JSON, `<custom name="…">` in XML - the same in the
 * results file and in the library's `collection_items`. What was there before is byte for byte what it was.
 *
 * A field is named by the player and holds what the player typed: markup in a name stays text, and a value that
 * looks like a formula never becomes one.
 */
final class OwnFieldsInExportsTest extends TestCase
{
    private const string ROW = '018d0000-0000-0000-0000-000000000001';
    private const string EMPTY_ROW = '018d0000-0000-0000-0000-000000000002';

    private const string MARKUP_NAME = 'custom: <script>alert("x")</script> & \'co\'';
    private const string FORMULA = '=HYPERLINK("http://evil.example","x")';

    /** The columns in order: every type, a field named like markup, a field nothing is written in */
    private const array LABELS = [
        'custom:private_notes',
        'custom: Breaks',
        'custom:started_on',
        'custom: Sorted first',
        'custom:condition',
        'custom:my_tags',
        'custom:how_i_liked_it',
        self::MARKUP_NAME,
        'custom: Nothing in it',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideExports(): iterable
    {
        yield 'the results file' => ['results'];
        yield 'the library\'s collection items' => ['library'];
    }

    #[DataProvider('provideExports')]
    public function testJsonHasTheOwnFieldsAsATypedObject(string $export): void
    {
        $rows = $this->jsonRows($export, $this->columns());

        self::assertSame('custom', array_key_last($rows[0]));
        self::assertIsArray($rows[0]['custom']);
        self::assertSame(self::LABELS, array_keys($rows[0]['custom']));
        self::assertSame([
            'custom:private_notes' => self::FORMULA,
            'custom: Breaks' => 2.5,
            'custom:started_on' => '2026-03-01',
            'custom: Sorted first' => false,
            'custom:condition' => 'like_new',
            'custom:my_tags' => ['=cmd', 'night sky'],
            'custom:how_i_liked_it' => 4,
            self::MARKUP_NAME => "one\ntwo",
            'custom: Nothing in it' => null,
        ], $rows[0]['custom']);
        // A row without any value: the same keys, all empty - an object, never a list
        self::assertSame(array_fill_keys(self::LABELS, null), $rows[1]['custom']);
        self::assertStringContainsString('"custom": {', $this->export($export, ExportFormat::Json, $this->columns()));

        // What was there before is what it was
        $before = $this->jsonRows($export, null);
        self::assertArrayNotHasKey('custom', $before[0]);

        foreach ($rows as $index => $row) {
            unset($row['custom']);
            self::assertSame($before[$index], $row);
        }
    }

    #[DataProvider('provideExports')]
    public function testXlsxHasAColumnPerFieldAfterEveryOtherAndTextIsNeverAFormula(string $export): void
    {
        $sheet = $this->sheet($export, $this->columns());
        $before = $this->sheet($export, null);
        $first = $before->getHighestDataColumn(1);
        $existing = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($first);

        // The existing cells are where and what they were
        self::assertSame($before->rangeToArray('A1:' . $first . '3'), $sheet->rangeToArray('A1:' . $first . '3'));

        $cell = static fn (int $offset, int $row) => $sheet->getCell([$existing + $offset, $row]);

        foreach (self::LABELS as $offset => $label) {
            self::assertSame($label, $cell($offset + 1, 1)->getValue());
            self::assertSame(DataType::TYPE_STRING, $cell($offset + 1, 1)->getDataType());
        }

        self::assertSame(self::FORMULA, $cell(1, 2)->getValue());
        self::assertSame(DataType::TYPE_STRING, $cell(1, 2)->getDataType(), 'Text that looks like a formula is text');
        self::assertSame(2.5, $cell(2, 2)->getValue());
        self::assertSame(DataType::TYPE_NUMERIC, $cell(2, 2)->getDataType());
        self::assertSame('2026-03-01', $cell(3, 2)->getValue());
        self::assertSame('false', $cell(4, 2)->getValue());
        self::assertSame('like_new', $cell(5, 2)->getValue());
        self::assertSame('=cmd, night sky', $cell(6, 2)->getValue());
        self::assertSame(DataType::TYPE_STRING, $cell(6, 2)->getDataType(), 'Tags that start like a formula are text');
        self::assertSame(4, $cell(7, 2)->getValue());
        self::assertSame("one\ntwo", $cell(8, 2)->getValue());
        self::assertNull($cell(9, 2)->getValue());
        // The row without values: empty cells
        self::assertNull($cell(1, 3)->getValue());
        self::assertNull($cell(7, 3)->getValue());
    }

    #[DataProvider('provideExports')]
    public function testCsvHasAColumnPerFieldAfterEveryOtherAndFormulaLookingTextGetsAnApostrophe(string $export): void
    {
        $rows = $this->csvRows($export, $this->columns());
        $before = $this->csvRows($export, null);
        $existing = count($before[0]);

        self::assertCount(3, $rows);

        foreach ($rows as $index => $row) {
            self::assertCount($existing + count(self::LABELS), $row);
            self::assertSame($before[$index], array_slice($row, 0, $existing), 'The existing columns are what they were');
        }

        self::assertSame(self::LABELS, array_slice($rows[0], $existing));
        self::assertSame([
            "'" . self::FORMULA,
            '2.5',
            '2026-03-01',
            'false',
            'like_new',
            "'=cmd, night sky",
            '4',
            "one\ntwo",
            '',
        ], array_slice($rows[1], $existing));
        self::assertSame(array_fill(0, count(self::LABELS), ''), array_slice($rows[2], $existing));
    }

    #[DataProvider('provideExports')]
    public function testXmlHasACustomElementPerFieldNamedByAnAttribute(string $export): void
    {
        $content = $this->export($export, ExportFormat::Xml, $this->columns());

        // A field's name is no element name - and markup in it is text, in an attribute
        self::assertStringNotContainsString('<script>', $content);
        $dom = self::xml($content);
        $records = (new \DOMXPath($dom))->query('//record');
        self::assertNotFalse($records);
        self::assertSame(2, $records->length);

        $custom = [];
        $record = $records->item(0);
        self::assertInstanceOf(\DOMElement::class, $record);

        foreach ($record->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->tagName === 'custom') {
                $custom[$child->getAttribute('name')] = $child;
            }
        }

        self::assertSame(self::LABELS, array_keys($custom));
        self::assertSame('custom', $record->lastElementChild?->tagName, 'After every other column');
        self::assertSame(self::FORMULA, $custom['custom:private_notes']->textContent);
        self::assertSame('2.5', $custom['custom: Breaks']->textContent);
        self::assertSame('2026-03-01', $custom['custom:started_on']->textContent);
        self::assertSame('false', $custom['custom: Sorted first']->textContent);
        self::assertSame('like_new', $custom['custom:condition']->textContent);
        self::assertSame('4', $custom['custom:how_i_liked_it']->textContent);
        self::assertSame("one\ntwo", $custom[self::MARKUP_NAME]->textContent);
        self::assertSame('', $custom['custom: Nothing in it']->textContent);
        // Tags: one <tag> each
        $tags = [];

        foreach ($custom['custom:my_tags']->getElementsByTagName('tag') as $tag) {
            $tags[] = $tag->textContent;
        }

        self::assertSame(['=cmd', 'night sky'], $tags);

        // What was there before is what it was: the same file once the custom elements are taken out
        foreach (iterator_to_array((new \DOMXPath($dom))->query('//custom') ?: []) as $element) {
            if ($element instanceof \DOMElement) {
                $element->parentNode?->removeChild($element);
            }
        }

        self::assertSame(self::elementTexts(self::xml($this->export($export, ExportFormat::Xml, null))), self::elementTexts($dom));
    }

    public function testAPlayerWithoutFieldsGetsTheFilesAsTheyAlwaysWere(): void
    {
        foreach (['results', 'library'] as $export) {
            foreach ([ExportFormat::Json, ExportFormat::Xml] as $format) {
                self::assertSame($this->export($export, $format, null), $this->export($export, $format, new OwnCustomColumns()), $export . ' ' . $format->value);
            }

            self::assertSame($this->csvRows($export, null), $this->csvRows($export, new OwnCustomColumns()), $export);
        }
    }

    public function testTheCsvReadmeExplainsTheOwnColumnsOnlyWhenThereAreSome(): void
    {
        self::assertStringContainsString('custom:', $this->unzip($this->export('library', ExportFormat::Csv, $this->columns()))['README.txt']);
        self::assertStringNotContainsString('custom', $this->unzip($this->export('library', ExportFormat::Csv, null))['README.txt']);
    }

    private function columns(): OwnCustomColumns
    {
        return new OwnCustomColumns(self::LABELS, [
            self::ROW => [
                'custom:private_notes' => self::FORMULA,
                'custom: Breaks' => 2.5,
                'custom:started_on' => '2026-03-01',
                'custom: Sorted first' => false,
                'custom:condition' => 'like_new',
                'custom:my_tags' => ['=cmd', 'night sky'],
                'custom:how_i_liked_it' => 4,
                self::MARKUP_NAME => "one\ntwo",
            ],
            // A row the export does not have: never written anywhere
            '018d0000-0000-0000-0000-00000000ffff' => ['custom: Breaks' => 99],
        ]);
    }

    private function export(string $export, ExportFormat $format, null|OwnCustomColumns $custom): string
    {
        if ($export === 'results') {
            return (new PuzzlerDataExporter())->export([$this->solvedResult(self::ROW), $this->solvedResult(self::EMPTY_ROW)], $format, $custom);
        }

        $rowIds = [self::ROW, self::EMPTY_ROW];
        $document = new ExportDocument('puzzle_library', ['format_version' => 1], [
            new ExportSection(
                name: 'collection_items',
                description: 'Every puzzle in your collections.',
                columns: ['collection_item_id', 'comment', 'pieces_count'],
                rows: array_map(static fn (string $id): array => ['collection_item_id' => $id, 'comment' => 'A comment', 'pieces_count' => 500], $rowIds),
                customColumns: $custom === null ? [] : $custom->labels,
                customRows: $custom === null || $custom->isEmpty() ? [] : array_map($custom->of(...), $rowIds),
            ),
        ]);

        return (new SectionedExportWriter())->write($document, $format)->content;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jsonRows(string $export, null|OwnCustomColumns $custom): array
    {
        /** @var array<mixed> $decoded */
        $decoded = json_decode($this->export($export, ExportFormat::Json, $custom), true, flags: JSON_THROW_ON_ERROR);
        $rows = $export === 'results' ? $decoded : ($decoded['collection_items'] ?? null);
        self::assertIsArray($rows);
        self::assertCount(2, $rows);

        /** @var list<array<string, mixed>> $rows */
        return $rows;
    }

    private function sheet(string $export, null|OwnCustomColumns $custom): Worksheet
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, $this->export($export, ExportFormat::Xlsx, $custom));
            $spreadsheet = (new XlsxReader())->load($tempFile);
        } finally {
            unlink($tempFile);
        }

        $sheet = $export === 'results' ? $spreadsheet->getActiveSheet() : $spreadsheet->getSheetByName('collection_items');
        self::assertNotNull($sheet);

        return $sheet;
    }

    /**
     * @return list<list<null|string>>
     */
    private function csvRows(string $export, null|OwnCustomColumns $custom): array
    {
        $content = $this->export($export, ExportFormat::Csv, $custom);

        if ($export === 'library') {
            $content = $this->unzip($content)['collection_items.csv'];
        }

        $handle = fopen('php://temp', 'r+');
        self::assertNotFalse($handle);
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $content): array
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'zip_test_');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, $content);
            $zip = new ZipArchive();
            self::assertTrue($zip->open($tempFile));
            $files = [];

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                self::assertIsString($name);
                $files[$name] = (string) $zip->getFromIndex($i);
            }

            $zip->close();
        } finally {
            unlink($tempFile);
        }

        return $files;
    }

    /**
     * Every element of the document with its text, in order - what two exports have in common once the custom
     * elements are out.
     *
     * @return list<string>
     */
    private static function elementTexts(\DOMDocument $dom): array
    {
        $texts = [];

        foreach ((new \DOMXPath($dom))->query('//*[not(*)]') ?: [] as $leaf) {
            if ($leaf instanceof \DOMElement) {
                $texts[] = $leaf->nodeName . '=' . $leaf->textContent;
            }
        }

        return $texts;
    }

    private static function xml(string $content): \DOMDocument
    {
        self::assertNotSame('', $content);
        $dom = new \DOMDocument();
        self::assertTrue($dom->loadXML($content));

        return $dom;
    }

    private function solvedResult(string $timeId): ExportableSolvingTime
    {
        return new ExportableSolvingTime(
            timeId: $timeId,
            puzzleId: '018d0000-0000-0000-0000-0000000000aa',
            puzzleName: 'Copper Lighthouse',
            brandName: 'Lantern Puzzle Works',
            piecesCount: 500,
            secondsToSolve: 1500,
            timeFormatted: '00:25:00',
            finishedAt: new DateTimeImmutable('2026-10-07 21:40:00'),
            trackedAt: new DateTimeImmutable('2026-10-07 21:45:00'),
            type: 'solo',
            firstAttempt: false,
            unboxed: false,
            playersCount: 1,
            teamMembers: '',
            finishedPuzzlePhotoUrl: null,
            comment: 'A comment',
            puzzleFastestTime: 1200,
            puzzleFastestTimeFormatted: '00:20:00',
            puzzleAverageTime: 1800,
            puzzleAverageTimeFormatted: '00:30:00',
            playerRank: 2,
            puzzleTotalSolved: 9,
        );
    }
}
