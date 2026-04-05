<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class CompetitionParticipantImporterTest extends KernelTestCase
{
    private CompetitionParticipantImporter $importer;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importer = self::getContainer()->get(CompetitionParticipantImporter::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testImportCreatesNewParticipants(): void
    {
        $file = $this->createXlsx([
            ['name', 'country', 'external_id', 'msp_player_id', 'status'],
            ['Alice Newbie', 'gb', 'EXT-A', '', 'active'],
            ['Bob Newbie', 'fr', '', '', ''],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(2, $result->added);
        self::assertSame(0, $result->updated);
        self::assertEmpty($result->errors);

        /** @var int $count */
        $count = $this->database->executeQuery(
            'SELECT COUNT(*) FROM competition_participant WHERE competition_id = :id AND deleted_at IS NULL',
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        )->fetchOne();

        self::assertSame(2, $count);
    }

    public function testImportUpdatesExistingByExternalId(): void
    {
        // PARTICIPANT_CONNECTED has external_id = 'EXT-001'
        $file = $this->createXlsx([
            ['name', 'country', 'external_id'],
            ['Updated Name', 'de', 'EXT-001'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);

        /** @var array{name: string, country: string} $row */
        $row = $this->database->executeQuery(
            'SELECT name, country FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
        )->fetchAssociative();

        self::assertSame('Updated Name', $row['name']);
        self::assertSame('de', $row['country']);
    }

    public function testImportUpdatesExistingByPlayerId(): void
    {
        $file = $this->createXlsx([
            ['name', 'msp_player_id'],
            ['Official Name', PlayerFixture::PLAYER_REGULAR],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);

        /** @var array{name: string} $row */
        $row = $this->database->executeQuery(
            'SELECT name FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED],
        )->fetchAssociative();

        self::assertSame('Official Name', $row['name']);
    }

    public function testImportRestoresSoftDeletedOnMatch(): void
    {
        // PARTICIPANT_DELETED is soft-deleted with name 'Deleted Person'
        $file = $this->createXlsx([
            ['name'],
            ['Deleted Person'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);

        /** @var array{deleted_at: string|null} $row */
        $row = $this->database->executeQuery(
            'SELECT deleted_at FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_DELETED],
        )->fetchAssociative();

        self::assertNull($row['deleted_at']);
    }

    public function testImportSetsSoftDeleteWhenStatusDeleted(): void
    {
        $file = $this->createXlsx([
            ['name', 'status'],
            ['New But Deleted', 'deleted'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->softDeleted);

        /** @var array{deleted_at: string|null} $row */
        $row = $this->database->executeQuery(
            "SELECT deleted_at FROM competition_participant WHERE name = 'New But Deleted'",
        )->fetchAssociative();

        self::assertNotNull($row['deleted_at']);
    }

    public function testImportSkipsRowsWithoutName(): void
    {
        $file = $this->createXlsx([
            ['name', 'country'],
            ['', 'cz'],
            ['Valid Name', 'cz'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('missing name', $result->errors[0]);
    }

    public function testImportWarnsOnInvalidCountryCode(): void
    {
        $file = $this->createXlsx([
            ['name', 'country'],
            ['Test Person', 'INVALID'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('invalid country code', $result->warnings[0]);
    }

    public function testImportWarnsOnNonexistentPlayerId(): void
    {
        $file = $this->createXlsx([
            ['name', 'msp_player_id'],
            ['Test Person', '00000000-0000-0000-0000-000000000099'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertCount(1, $result->errors);
        self::assertStringContainsString('does not exist', $result->errors[0]);
    }

    public function testImportIsIdempotent(): void
    {
        $file1 = $this->createXlsx([
            ['name', 'country'],
            ['Idempotent Person', 'cz'],
        ]);

        $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file1);
        unlink($file1);

        $file2 = $this->createXlsx([
            ['name', 'country'],
            ['Idempotent Person', 'cz'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file2);
        unlink($file2);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);

        /** @var int $count */
        $count = $this->database->executeQuery(
            "SELECT COUNT(*) FROM competition_participant WHERE name = 'Idempotent Person' AND competition_id = :id",
            ['id' => CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024],
        )->fetchOne();

        self::assertSame(1, $count);
    }

    public function testImportDetectsDuplicateNamesInFile(): void
    {
        $file = $this->createXlsx([
            ['name'],
            ['Same Name', ''],
            ['Same Name', ''],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('duplicate name', $result->warnings[0]);
    }

    /**
     * @param array<array<string>> $rows
     */
    private function createXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $colIndex => $value) {
                $sheet->setCellValue([$colIndex + 1, $rowIndex + 1], $value);
            }
        }

        $tempFile = tempnam(sys_get_temp_dir(), 'test_import_');
        assert(is_string($tempFile));

        $writer = new Xlsx($spreadsheet);
        $writer->save($tempFile);

        return $tempFile;
    }
}
