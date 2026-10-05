<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
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

    public function testImportSkipsTeamAssignmentWhenTeamNameIsTooLong(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_name', 'team_name'],
            ['Long Team Member', 'Team Round', str_repeat('x', CompetitionTeam::NAME_MAX_LENGTH + 1)],
        ]);

        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertSame([sprintf('Row 2: team name is longer than %d characters, team assignment skipped.', CompetitionTeam::NAME_MAX_LENGTH)], $result->warnings);

        /** @var false|null|string $teamId */
        $teamId = $this->database->executeQuery(
            'SELECT cpr.team_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.name = :name AND cpr.round_id = :roundId',
            ['name' => 'Long Team Member', 'roundId' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        )->fetchOne();

        self::assertNull($teamId);
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

    public function testImportAdoptsSelfJoinedRowOfListedPlayer(): void
    {
        // PARTICIPANT_SELF_JOINED 'Michael Johnson' is linked to PLAYER_WITH_FAVORITES
        $file = $this->createXlsx([
            ['name', 'country', 'msp_player_id'],
            ['Michael Johnson', 'de', PlayerFixture::PLAYER_WITH_FAVORITES],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);

        /** @var array{source: string, player_id: string} $row */
        $row = $this->database->executeQuery(
            'SELECT source, player_id FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
        )->fetchAssociative();

        // Now on the organizer's list: leaving disconnects instead of deleting the row
        self::assertSame('imported', $row['source']);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $row['player_id']);
    }

    public function testImportNeverRestoresSelfJoinedRowThePlayerLeft(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_participant SET deleted_at = now() WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
        );

        $file = $this->createXlsx([
            ['name'],
            ['Michael Johnson'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);

        /** @var array{deleted_at: null|string} $row */
        $row = $this->database->executeQuery(
            'SELECT deleted_at FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED],
        )->fetchAssociative();

        self::assertNotNull($row['deleted_at']);
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
