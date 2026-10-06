<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\Export;

use LogicException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\Export\ExportDocument;
use SpeedPuzzling\Web\Results\Export\ExportSection;
use SpeedPuzzling\Web\Services\Export\SectionedExportWriter;
use SpeedPuzzling\Web\Value\ExportFormat;
use ZipArchive;

final class SectionedExportWriterTest extends TestCase
{
    private SectionedExportWriter $writer;

    protected function setUp(): void
    {
        $this->writer = new SectionedExportWriter();
    }

    public function testJsonHasTheAboutObjectAndOneListPerSectionInColumnOrder(): void
    {
        $file = $this->writer->write($this->document(), ExportFormat::Json);

        self::assertSame('application/json', $file->contentType);
        self::assertSame('json', $file->fileExtension);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($file->content, true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(['export', 'items', 'empty'], array_keys($decoded));

        /** @var array{export: array{format_version: int, sections: array<string, mixed>}, items: list<array<string, mixed>>, empty: list<mixed>} $json */
        $json = $decoded;
        self::assertSame(1, $json['export']['format_version']);
        self::assertSame(['rows' => 2, 'description' => 'Things.'], $json['export']['sections']['items']);
        self::assertSame([], $json['empty']);
        self::assertSame(['id', 'name', 'count', 'active', 'note'], array_keys($json['items'][0]));
        self::assertSame('Žluťoučký & <kůň>', $json['items'][0]['name']);
        self::assertTrue($json['items'][0]['active']);
        self::assertNull($json['items'][1]['note']);
        self::assertStringContainsString('https://example.com/a', $file->content, 'Slashes are not escaped.');
    }

    public function testXlsxHasAnAboutSheetAndOneSheetPerSectionWithTextNeverRunAsAFormula(): void
    {
        $file = $this->writer->write($this->document(), ExportFormat::Xlsx);

        self::assertSame('xlsx', $file->fileExtension);

        $spreadsheet = $this->readXlsx($file->content);

        self::assertSame(['about', 'items', 'empty'], $spreadsheet->getSheetNames());

        $items = $spreadsheet->getSheetByName('items');
        self::assertNotNull($items);
        self::assertSame('id', $items->getCell('A1')->getValue());
        self::assertSame('Žluťoučký & <kůň>', $items->getCell('B2')->getValue());
        self::assertSame(3, $items->getCell('C2')->getValue());
        self::assertSame('true', $items->getCell('D2')->getValue());

        $formula = $items->getCell('B3');
        self::assertSame('=HYPERLINK("http://evil.example","click")', $formula->getValue());
        self::assertSame(DataType::TYPE_STRING, $formula->getDataType());

        $empty = $spreadsheet->getSheetByName('empty');
        self::assertNotNull($empty);
        self::assertSame('only_header', $empty->getCell('A1')->getValue());
        self::assertSame(1, $empty->getHighestDataRow());

        $about = $spreadsheet->getSheetByName('about');
        self::assertNotNull($about);
        self::assertSame('format_version', $about->getCell('A2')->getValue());
    }

    public function testCsvIsAZipWithOneFilePerSectionAboutAndReadme(): void
    {
        $file = $this->writer->write($this->document(), ExportFormat::Csv);

        self::assertSame('application/zip', $file->contentType);
        self::assertSame('zip', $file->fileExtension);

        $files = $this->unzip($file->content);

        self::assertSame(['README.txt', 'about.csv', 'items.csv', 'empty.csv'], array_keys($files));
        self::assertSame("only_header\n", $files['empty.csv']);
        self::assertStringContainsString('items.csv (2 rows) - Things.', $files['README.txt']);

        $lines = explode("\n", trim($files['items.csv']));
        self::assertSame('id,name,count,active,note', $lines[0]);
        self::assertSame('1,"Žluťoučký & <kůň>",3,true,https://example.com/a', $lines[1]);
        self::assertSame('2,"\'=HYPERLINK(""http://evil.example"",""click"")",0,false,', $lines[2]);
    }

    public function testXmlNamesEveryElementLikeTheOtherFormats(): void
    {
        $file = $this->writer->write($this->document(), ExportFormat::Xml);

        $xml = simplexml_load_string($file->content);
        self::assertNotFalse($xml);

        self::assertSame('test_export', $xml->getName());
        self::assertSame('1', (string) $xml->about->format_version);
        self::assertSame('2', (string) $xml->items['rows']);
        self::assertSame('Žluťoučký & <kůň>', (string) $xml->items->record[0]->name);
        self::assertSame('true', (string) $xml->items->record[0]->active);
        self::assertSame('0', (string) $xml->empty['rows']);
    }

    public function testARowMissingAColumnIsABug(): void
    {
        $document = new ExportDocument('test_export', [], [
            new ExportSection('broken', 'Broken.', ['a', 'b'], [['a' => 1]]),
        ]);

        $this->expectException(LogicException::class);
        $this->writer->write($document, ExportFormat::Json);
    }

    private function document(): ExportDocument
    {
        return new ExportDocument(
            rootName: 'test_export',
            about: ['format_version' => 1, 'player_name' => 'Jan'],
            sections: [
                new ExportSection(
                    name: 'items',
                    description: 'Things.',
                    columns: ['id', 'name', 'count', 'active', 'note'],
                    rows: [
                        ['note' => 'https://example.com/a', 'id' => '1', 'name' => 'Žluťoučký & <kůň>', 'count' => 3, 'active' => true],
                        ['id' => '2', 'name' => '=HYPERLINK("http://evil.example","click")', 'count' => 0, 'active' => false, 'note' => null],
                    ],
                ),
                new ExportSection('empty', 'Nothing.', ['only_header'], []),
            ],
        );
    }

    private function readXlsx(string $content): \PhpOffice\PhpSpreadsheet\Spreadsheet
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, $content);

            return (new XlsxReader())->load($tempFile);
        } finally {
            unlink($tempFile);
        }
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

            return $files;
        } finally {
            unlink($tempFile);
        }
    }
}
