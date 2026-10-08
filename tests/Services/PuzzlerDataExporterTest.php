<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use SpeedPuzzling\Web\Results\ExportableSolvingTime;
use SpeedPuzzling\Web\Services\PuzzlerDataExporter;
use SpeedPuzzling\Web\Value\ExportFormat;
use PHPUnit\Framework\TestCase;

final class PuzzlerDataExporterTest extends TestCase
{
    private PuzzlerDataExporter $exporter;

    protected function setUp(): void
    {
        $this->exporter = new PuzzlerDataExporter();
    }

    /**
     * @return array<ExportableSolvingTime>
     */
    private function createSampleData(): array
    {
        return [
            new ExportableSolvingTime(
                timeId: '018d0000-0000-0000-0000-000000000001',
                puzzleId: '018d0000-0000-0000-0000-000000000002',
                puzzleName: 'Test Puzzle',
                brandName: 'Test Brand',
                piecesCount: 1000,
                secondsToSolve: 3661,
                timeFormatted: '01:01:01',
                finishedAt: new DateTimeImmutable('2024-01-15 10:00:00'),
                trackedAt: new DateTimeImmutable('2024-01-15 10:00:00'),
                type: 'solo',
                firstAttempt: true,
                unboxed: false,
                playersCount: 1,
                teamMembers: '',
                finishedPuzzlePhotoUrl: 'https://example.com/photo.jpg',
                comment: 'Great puzzle!',
                puzzleFastestTime: 3000,
                puzzleFastestTimeFormatted: '00:50:00',
                puzzleAverageTime: 4500,
                puzzleAverageTimeFormatted: '01:15:00',
                playerRank: 5,
                puzzleTotalSolved: 42,
            ),
        ];
    }

    public function testJsonExportIsValidJson(): void
    {
        $data = $this->createSampleData();
        $result = $this->exporter->export($data, ExportFormat::Json);

        /** @var array<int, array<string, mixed>>|null $decoded */
        $decoded = json_decode($result, true);
        $this->assertNotNull($decoded);
        $this->assertCount(1, $decoded);
        $this->assertSame('Test Puzzle', $decoded[0]['puzzle_name']);
    }

    public function testPpmIsTheLastColumnPerPerson(): void
    {
        $data = $this->createSampleData();

        /** @var array<int, array<string, mixed>> $decoded */
        $decoded = json_decode($this->exporter->export($data, ExportFormat::Json), true);
        // 1000 pieces in 3661 s
        self::assertSame(16.39, $decoded[0]['ppm']);

        $csv = $this->exporter->export($data, ExportFormat::Csv);
        $header = explode("\n", $csv)[0];
        self::assertStringEndsWith('"puzzle_total_solved","ppm"', trim($header));

        $pair = new ExportableSolvingTime(
            timeId: '018d0000-0000-0000-0000-000000000003',
            puzzleId: '018d0000-0000-0000-0000-000000000002',
            puzzleName: 'Test Puzzle',
            brandName: 'Test Brand',
            piecesCount: 1000,
            secondsToSolve: 3000,
            timeFormatted: '00:50:00',
            finishedAt: null,
            trackedAt: new DateTimeImmutable('2024-01-15 10:00:00'),
            type: 'duo',
            firstAttempt: false,
            unboxed: false,
            playersCount: 2,
            teamMembers: 'A, B',
            finishedPuzzlePhotoUrl: null,
            comment: null,
            puzzleFastestTime: null,
            puzzleFastestTimeFormatted: '',
            puzzleAverageTime: null,
            puzzleAverageTimeFormatted: '',
            playerRank: null,
            puzzleTotalSolved: 1,
        );
        self::assertSame(10.0, $pair->ppm);
    }

    public function testCsvExportContainsHeaders(): void
    {
        $data = $this->createSampleData();
        $result = $this->exporter->export($data, ExportFormat::Csv);

        $this->assertStringContainsString('result_id', $result);
        $this->assertStringContainsString('puzzle_name', $result);
        $this->assertStringContainsString('brand_name', $result);
        $this->assertStringContainsString('pieces_count', $result);
        $this->assertStringContainsString('players_count', $result);
    }

    public function testXmlExportIsValidXml(): void
    {
        $data = $this->createSampleData();
        $result = $this->exporter->export($data, ExportFormat::Xml);

        $this->assertStringStartsWith('<?xml', $result);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($result);
        $this->assertNotFalse($xml, 'XML should be valid');
        $this->assertSame('solving_times', $xml->getName());
    }

    public function testXlsxExportIsNotEmpty(): void
    {
        $data = $this->createSampleData();
        $result = $this->exporter->export($data, ExportFormat::Xlsx);

        $this->assertNotEmpty($result);
        // XLSX files start with PK (zip archive)
        $this->assertStringStartsWith('PK', $result);
    }

    public function testEmptyDataExport(): void
    {
        /** @var array<ExportableSolvingTime> $emptyData */
        $emptyData = [];
        $result = $this->exporter->export($emptyData, ExportFormat::Json);

        /** @var array<mixed>|null $decoded */
        $decoded = json_decode($result, true);
        $this->assertIsArray($decoded);
        $this->assertCount(0, $decoded);
    }

    /**
     * Team member names are typed by other players - they must never become a formula in the downloading
     * player's spreadsheet (docs/features/data-export.md, Q4).
     */
    public function testXlsxWritesTextAsTextAndNumbersAsNumbers(): void
    {
        $content = (new PuzzlerDataExporter())->export([$this->timeWithFormulaTeamMembers()], ExportFormat::Xlsx);

        $tempFile = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        self::assertIsString($tempFile);

        try {
            file_put_contents($tempFile, $content);
            $sheet = (new XlsxReader())->load($tempFile)->getActiveSheet();
        } finally {
            unlink($tempFile);
        }

        // team_members is column N, seconds_to_solve column F
        self::assertSame('team_members', $sheet->getCell('N1')->getValue());
        self::assertSame('=HYPERLINK("http://evil.example","x")', $sheet->getCell('N2')->getValue());
        self::assertSame(DataType::TYPE_STRING, $sheet->getCell('N2')->getDataType());
        self::assertSame(1500, $sheet->getCell('F2')->getValue());
        self::assertSame('true', $sheet->getCell('K2')->getValue());
    }

    public function testCsvPrefixesFormulaLookingText(): void
    {
        $content = (new PuzzlerDataExporter())->export([$this->timeWithFormulaTeamMembers()], ExportFormat::Csv);

        self::assertStringContainsString('"\'=HYPERLINK(""http://evil.example"",""x"")"', $content);
        self::assertStringContainsString('"1500"', $content);
    }

    private function timeWithFormulaTeamMembers(): ExportableSolvingTime
    {
        return new ExportableSolvingTime(
            timeId: 'time-1',
            puzzleId: 'puzzle-1',
            puzzleName: 'Puzzle',
            brandName: 'Brand',
            piecesCount: 500,
            secondsToSolve: 1500,
            timeFormatted: '00:25:00',
            finishedAt: new DateTimeImmutable('2026-10-01 10:00:00'),
            trackedAt: new DateTimeImmutable('2026-10-01 10:05:00'),
            type: 'duo',
            firstAttempt: true,
            unboxed: false,
            playersCount: 2,
            teamMembers: '=HYPERLINK("http://evil.example","x")',
            finishedPuzzlePhotoUrl: null,
            comment: null,
            puzzleFastestTime: null,
            puzzleFastestTimeFormatted: '',
            puzzleAverageTime: null,
            puzzleAverageTimeFormatted: '',
            playerRank: null,
            puzzleTotalSolved: 1,
        );
    }
}
