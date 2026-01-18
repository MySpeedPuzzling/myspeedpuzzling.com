<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
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
}
