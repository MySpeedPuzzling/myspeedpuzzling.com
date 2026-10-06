<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\CompetitionParticipantExporter;
use SpeedPuzzling\Web\Services\CompetitionParticipantImporter;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\TranslatableMessage;
use Symfony\Contracts\Translation\TranslatorInterface;

final class CompetitionParticipantImporterTest extends KernelTestCase
{
    private CompetitionParticipantImporter $importer;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->importer = self::getContainer()->get(CompetitionParticipantImporter::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
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
        self::assertStringContainsString('missing name', $this->texts($result->errors)[0]);
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
        self::assertStringContainsString('invalid country code', $this->texts($result->warnings)[0]);
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
        self::assertStringContainsString('does not exist', $this->texts($result->errors)[0]);
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
        self::assertSame([sprintf('Row 2: team name is longer than %d characters, team assignment skipped.', CompetitionTeam::NAME_MAX_LENGTH)], $this->texts($result->warnings));

        /** @var false|null|string $teamId */
        $teamId = $this->database->executeQuery(
            'SELECT cpr.team_id FROM competition_participant_round cpr INNER JOIN competition_participant cp ON cp.id = cpr.participant_id WHERE cp.name = :name AND cpr.round_id = :roundId',
            ['name' => 'Long Team Member', 'roundId' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        )->fetchOne();

        self::assertNull($teamId);
    }

    public function testRowsOfTheSamePersonCountAsOnePersonWithoutWarning(): void
    {
        // The documented way to list several rounds: one row per round
        $file = $this->createXlsx([
            ['name'],
            ['Same Name', ''],
            ['Same Name', ''],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame([], $this->texts($result->warnings));
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

    public function testTemplateFilledInAndImportedAssignsAllListedRounds(): void
    {
        // Exactly what an organiser does: download the template, fill it in, upload it
        $template = tempnam(sys_get_temp_dir(), 'test_template_');
        assert(is_string($template));
        file_put_contents($template, self::getContainer()->get(CompetitionParticipantExporter::class)->downloadTemplate());

        $spreadsheet = IOFactory::load($template);
        $sheet = $spreadsheet->getActiveSheet();
        /** @var array<int, null|string> $headers */
        $headers = $sheet->toArray()[0];
        $column = array_flip(array_map(static fn (null|string $header): string => (string) $header, $headers));
        $sheet->setCellValue([$column['name'] + 1, 2], 'Template Puzzler');
        $sheet->setCellValue([$column['country'] + 1, 2], 'us');
        $sheet->setCellValue([$column['round_names'] + 1, 2], 'Qualification Round, final round');
        (new Xlsx($spreadsheet))->save($template);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $template);
        unlink($template);

        self::assertSame(1, $result->added);
        self::assertSame([], $this->texts($result->warnings));
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Template Puzzler'),
        );
    }

    public function testExportedFileImportedBackKeepsEveryRound(): void
    {
        $before = $this->allRoundAssignments(CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertNotSame([], $before);

        $export = tempnam(sys_get_temp_dir(), 'test_export_');
        assert(is_string($export));
        file_put_contents($export, self::getContainer()->get(CompetitionParticipantExporter::class)->export(CompetitionFixture::COMPETITION_WJPC_2024));

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $export);
        unlink($export);

        self::assertSame(0, $result->added);
        self::assertSame([], $result->errors);
        foreach ($this->texts($result->warnings) as $warning) {
            self::assertStringNotContainsString('does not exist', $warning);
            self::assertStringNotContainsString('Unknown column', $warning);
        }
        self::assertSame($before, $this->allRoundAssignments(CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testRoundsFromSeveralRowsOfTheSamePersonAddUp(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_name'],
            ['Multi Round Puzzler', 'Qualification Round'],
            ['Multi Round Puzzler', 'Final Round'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(1, $result->added);
        self::assertSame(0, $result->updated);
        self::assertSame([], $this->texts($result->warnings));
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Multi Round Puzzler'),
        );
    }

    public function testImportNeverRemovesARoundTheParticipantAlreadyHas(): void
    {
        // PARTICIPANT_CONNECTED 'John Regular' (EXT-001) is in both WJPC rounds
        $file = $this->createXlsx([
            ['name', 'external_id', 'round_names'],
            ['John Regular', 'EXT-001', 'Final Round'],
        ]);

        $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'John Regular'),
        );
    }

    public function testUnknownRoundsAndColumnsAreReported(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_names', 'shirt_size'],
            ['Known And Unknown', 'Qualification Round, Semifinal', 'M'],
            ['Only Unknown', 'semifinal', 'L'],
        ]);

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(2, $result->added);
        self::assertContains(
            'Unknown column(s) ignored: "shirt_size". Columns the import reads: ' . implode(', ', CompetitionParticipantImporter::KNOWN_COLUMNS) . ', team_name: <round>.',
            $this->texts($result->warnings),
        );
        self::assertContains(
            'Round "Semifinal" does not exist in this event (row 2), not assigned. Rounds of this event: "Qualification Round", "Final Round".',
            $this->texts($result->warnings),
        );
        self::assertContains(
            'Round "semifinal" does not exist in this event (row 3), not assigned. Rounds of this event: "Qualification Round", "Final Round".',
            $this->texts($result->warnings),
        );
        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Known And Unknown'),
        );
        self::assertSame([], $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Only Unknown'));
    }

    public function testTeamNameGoesToTheTeamRoundsOfTheRowOnly(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_names', 'team_name'],
            ['Teammate One', 'Solo Round, Team Round', 'Dream Team'],
            ['Teammate Two', 'Team Round', 'Dream Team'],
        ]);

        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $file);
        unlink($file);

        self::assertSame([], $this->texts($result->warnings));

        /** @var list<array{name: string, round_id: string, team_name: null|string}> $rows */
        $rows = $this->database->executeQuery(
            'SELECT cp.name, cpr.round_id, ct.name AS team_name
             FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             LEFT JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE cp.competition_id = :id AND cp.name LIKE :name
             ORDER BY cp.name, cpr.round_id',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1, 'name' => 'Teammate%'],
        )->fetchAllAssociative();

        self::assertSame([
            ['name' => 'Teammate One', 'round_id' => CompetitionSeriesFixture::ROUND_OFFLINE_SOLO, 'team_name' => null],
            ['name' => 'Teammate One', 'round_id' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, 'team_name' => 'Dream Team'],
            ['name' => 'Teammate Two', 'round_id' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, 'team_name' => 'Dream Team'],
        ], $rows);
    }

    public function testReimportFillsAMissingTeamButNeverMovesToAnotherTeam(): void
    {
        $first = $this->createXlsx([
            ['name', 'round_names'],
            ['Late Teammate', 'Team Round'],
        ]);
        $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $first);
        unlink($first);
        self::assertNull($this->teamOf('Late Teammate'));

        $second = $this->createXlsx([
            ['name', 'round_names', 'team_name'],
            ['Late Teammate', 'Team Round', 'Night Owls'],
        ]);
        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $second);
        unlink($second);
        self::assertSame([], $this->texts($result->warnings));
        self::assertSame('Night Owls', $this->teamOf('Late Teammate'));

        $third = $this->createXlsx([
            ['name', 'round_names', 'team_name'],
            ['Late Teammate', 'Team Round', 'Early Birds'],
        ]);
        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $third);
        unlink($third);
        self::assertSame(
            ['"Late Teammate" stays in team "Night Owls" in round "Team Round", team "Early Birds" from the file ignored (an import never moves anybody to another team).'],
            $this->texts($result->warnings),
        );
        self::assertSame('Night Owls', $this->teamOf('Late Teammate'));
    }

    private function teamOf(string $participantName): null|string
    {
        /** @var false|null|string $team */
        $team = $this->database->executeQuery(
            'SELECT ct.name FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             LEFT JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE cp.competition_id = :id AND cp.name = :name AND cpr.round_id = :roundId',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1, 'name' => $participantName, 'roundId' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        )->fetchOne();
        self::assertNotFalse($team, 'The participant is in the team round');

        return $team;
    }

    public function testExportWithTeamsImportedBackChangesNothing(): void
    {
        // Anna and Ben are pair "Speedy" in the pair round and in the team round without a team yet
        $pairRoundId = $this->addRound(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Pair Round', RoundCategory::Duo);
        $pairRound = $this->entityManager->find(CompetitionRound::class, $pairRoundId);
        $teamRound = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        assert($pairRound instanceof CompetitionRound && $teamRound instanceof CompetitionRound);
        $speedy = new CompetitionTeam(Uuid::uuid7(), $pairRound, 'Speedy');
        $this->entityManager->persist($speedy);
        foreach (['Anna Pairing', 'Ben Pairing'] as $name) {
            $participant = $this->addParticipant(CompetitionSeriesFixture::EDITION_OFFLINE_1, $name, 'us');
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $pairRound, $speedy));
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $teamRound));
        }
        $this->entityManager->flush();

        $assignmentsBefore = $this->allRoundAssignments(CompetitionSeriesFixture::EDITION_OFFLINE_1);
        $teamsBefore = $this->teamCount(CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $result = $this->importExportOf(CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertSame(0, $result->added);
        self::assertSame([], $this->texts($result->warnings));
        self::assertSame([], $this->texts($result->errors));
        self::assertSame($assignmentsBefore, $this->allRoundAssignments(CompetitionSeriesFixture::EDITION_OFFLINE_1));
        self::assertSame($teamsBefore, $this->teamCount(CompetitionSeriesFixture::EDITION_OFFLINE_1));
    }

    public function testExportOfSameNamedParticipantsImportedBackKeepsThemApart(): void
    {
        $first = $this->addParticipant(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Jennifer Smith', 'us');
        $second = $this->addParticipant(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Jennifer Smith', 'us');
        $solo = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_SOLO);
        $team = $this->entityManager->find(CompetitionRound::class, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        assert($solo instanceof CompetitionRound && $team instanceof CompetitionRound);
        $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $first, $solo));
        $smiths = new CompetitionTeam(Uuid::uuid7(), $team, 'Smiths');
        $this->entityManager->persist($smiths);
        $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $second, $team, $smiths));
        $this->entityManager->flush();

        $before = $this->allRoundAssignments(CompetitionSeriesFixture::EDITION_OFFLINE_1);

        $result = $this->importExportOf(CompetitionSeriesFixture::EDITION_OFFLINE_1);

        self::assertSame([], $this->texts($result->warnings));
        self::assertSame(0, $result->added);
        self::assertSame($before, $this->allRoundAssignments(CompetitionSeriesFixture::EDITION_OFFLINE_1));
    }

    public function testSameNameWithoutIdIsReportedNeverMergedIntoOneOfThem(): void
    {
        $this->addParticipant(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Jennifer Smith', 'us');
        $this->addParticipant(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Jennifer Smith', 'us');
        $this->entityManager->flush();

        $file = $this->createXlsx([
            ['name', 'country', 'round_name'],
            ['Jennifer Smith', 'us', 'Solo Round'],
            ['Jennifer Smith', 'us', 'Team Round'],
        ]);
        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $file);
        unlink($file);

        $ambiguous = 'Row %d: 2 participants of this event are called "Jennifer Smith", row skipped. Add their participant_id (from an export) or an external_id to tell them apart.';
        self::assertSame([sprintf($ambiguous, 2), sprintf($ambiguous, 3)], $this->texts($result->warnings));
        self::assertSame(0, $result->updated);

        /** @var int $assigned */
        $assigned = $this->database->fetchOne(
            'SELECT count(*) FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cp.competition_id = :id AND cp.name = :name',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1, 'name' => 'Jennifer Smith'],
        );
        self::assertSame(0, $assigned, 'Neither Jennifer got rounds meant for "a" Jennifer');
    }

    public function testLaterRowsSeeWhatEarlierRowsChanged(): void
    {
        // Row 2 gives Jane an external id, row 3 renames her through it
        $file = $this->createXlsx([
            ['name', 'external_id'],
            ['Jane Unconnected', 'NEW-EXT'],
            ['Jane Renamed', 'NEW-EXT'],
        ]);
        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(0, $result->added);
        self::assertSame(1, $result->updated);
        self::assertSame('Jane Renamed', $this->database->fetchOne(
            'SELECT name FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        ));
    }

    public function testSemicolonsSeparateRoundsToo(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_names'],
            ['Semicolon Puzzler', 'Qualification Round; Final Round'],
        ]);
        $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Semicolon Puzzler'),
        );
    }

    public function testRoundWhoseNameContainsACommaIsRecognisedInTheList(): void
    {
        $this->database->executeStatement(
            'UPDATE competition_round SET name = :name WHERE id = :id',
            ['name' => 'Team, Relay', 'id' => CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
        );

        $file = $this->createXlsx([
            ['name', 'round_names'],
            ['Comma Puzzler', 'Solo Round, Team, Relay'],
            ['Comma Alone', 'team,relay'],
        ]);
        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $file);
        unlink($file);

        self::assertSame([], $this->texts($result->warnings));
        self::assertSame(
            [CompetitionSeriesFixture::ROUND_OFFLINE_SOLO, CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
            $this->roundsOf(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Comma Puzzler'),
        );
        self::assertSame(
            [CompetitionSeriesFixture::ROUND_OFFLINE_TEAM],
            $this->roundsOf(CompetitionSeriesFixture::EDITION_OFFLINE_1, 'Comma Alone'),
        );
    }

    public function testRoundNameAndRoundNamesColumnsAddUp(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_names', 'round_name'],
            ['Both Columns', 'Qualification Round', 'Final Round'],
        ]);
        $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(
            [CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION, CompetitionRoundFixture::ROUND_WJPC_FINAL],
            $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Both Columns'),
        );
    }

    public function testDeletedRowGetsNoRounds(): void
    {
        $file = $this->createXlsx([
            ['name', 'status', 'round_names'],
            ['Gone Puzzler', 'deleted', 'Qualification Round'],
        ]);
        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(1, $result->softDeleted);
        self::assertSame(0, $result->added);
        self::assertSame([], $this->roundsOf(CompetitionFixture::COMPETITION_WJPC_2024, 'Gone Puzzler'));
    }

    public function testTeamNamesMatchIgnoringCase(): void
    {
        $file = $this->createXlsx([
            ['name', 'round_names', 'team_name'],
            ['Case One', 'Team Round', 'Dream Team'],
            ['Case Two', 'Team Round', 'dream team'],
        ]);
        $result = $this->importer->import(CompetitionSeriesFixture::EDITION_OFFLINE_1, $file);
        unlink($file);

        self::assertSame([], $this->texts($result->warnings));
        /** @var int $teams */
        $teams = $this->database->fetchOne(
            'SELECT count(DISTINCT cpr.team_id) FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cp.competition_id = :id AND cp.name LIKE :name AND cpr.team_id IS NOT NULL',
            ['id' => CompetitionSeriesFixture::EDITION_OFFLINE_1, 'name' => 'Case %'],
        );
        self::assertSame(1, $teams);
    }

    public function testRoundsDifferingOnlyInCaseAreReported(): void
    {
        $this->addRound(CompetitionFixture::COMPETITION_WJPC_2024, 'final round', RoundCategory::Solo);

        $file = $this->createXlsx([
            ['name', 'round_names'],
            ['Case Puzzler', 'Final Round'],
        ]);
        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertCount(1, $result->warnings);
        self::assertStringContainsString('differ only in upper/lower case', $this->texts($result->warnings)[0]);
    }

    public function testFileThatIsNotASpreadsheetIsReportedNotThrown(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'test_zip_');
        assert(is_string($file));
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $zip->addFromString('readme.txt', 'not a spreadsheet');
        $zip->close();

        $result = $this->importer->import(CompetitionFixture::COMPETITION_WJPC_2024, $file);
        unlink($file);

        self::assertSame(
            ['The file could not be read. Please upload an .xlsx file saved from Excel, Numbers or Google Sheets.'],
            $this->texts($result->errors),
        );
    }

    /**
     * @param array<TranslatableMessage> $messages
     * @return list<string>
     */
    private function texts(array $messages): array
    {
        $translator = self::getContainer()->get(TranslatorInterface::class);

        return array_values(array_map(static fn (TranslatableMessage $message): string => $message->trans($translator, 'en'), $messages));
    }

    private function importExportOf(string $competitionId): ParticipantImportResult
    {
        $export = tempnam(sys_get_temp_dir(), 'test_export_');
        assert(is_string($export));
        file_put_contents($export, self::getContainer()->get(CompetitionParticipantExporter::class)->export($competitionId));

        $result = $this->importer->import($competitionId, $export);
        unlink($export);

        return $result;
    }

    private function addRound(string $competitionId, string $name, RoundCategory $category): string
    {
        $competition = $this->entityManager->find(Competition::class, $competitionId);
        assert($competition instanceof Competition);

        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: $name,
            minutesLimit: 60,
            startsAt: new DateTimeImmutable('+40 days'),
            category: $category,
        );
        $this->entityManager->persist($round);
        $this->entityManager->flush();

        return $round->id->toString();
    }

    private function addParticipant(string $competitionId, string $name, string $country): CompetitionParticipant
    {
        $competition = $this->entityManager->find(Competition::class, $competitionId);
        assert($competition instanceof Competition);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, $country, $competition);
        $this->entityManager->persist($participant);

        return $participant;
    }

    private function teamCount(string $competitionId): int
    {
        /** @var int $count */
        $count = $this->database->fetchOne(
            'SELECT count(*) FROM competition_team ct INNER JOIN competition_round cr ON cr.id = ct.round_id WHERE cr.competition_id = :id',
            ['id' => $competitionId],
        );

        return $count;
    }

    /**
     * @return list<string> round ids, ordered
     */
    private function roundsOf(string $competitionId, string $participantName): array
    {
        /** @var list<string> $roundIds */
        $roundIds = $this->database->executeQuery(
            'SELECT cpr.round_id FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cp.competition_id = :id AND cp.name = :name
             ORDER BY cpr.round_id',
            ['id' => $competitionId, 'name' => $participantName],
        )->fetchFirstColumn();

        return $roundIds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allRoundAssignments(string $competitionId): array
    {
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->database->executeQuery(
            'SELECT cpr.participant_id, cpr.round_id, cpr.team_id FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             WHERE cp.competition_id = :id
             ORDER BY cpr.participant_id, cpr.round_id',
            ['id' => $competitionId],
        )->fetchAllAssociative();

        return $rows;
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
