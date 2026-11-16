<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\RecalculateBadgesForPlayer;
use SpeedPuzzling\Web\Message\RecalculateXpChainForSolve;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Unfinished teams of an organizer's results sheet (docs/features/event-results-import/README.md "Unfinished results
 * (D9)"): a "Pieces placed" row of a tab with a Time limit is an unfinished result - pieces placed, the limit as time
 * spent, no time; pieces placed must be fewer than the puzzle has, and without a limit the tab is a problem. The upsert
 * moves a team between finished and unfinished in place. PUZZLE_500_01 (500 pieces), made-up event and teams.
 */
final class ApplyEventResultsTabUnfinishedTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Puzzle Link- manufacturer';
    private const string GID = '515151';

    private MessageBusInterface $messageBus;
    private Connection $database;
    private EntityManagerInterface $entityManager;
    private string $sheetId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $organization = $this->entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $this->entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($creator);

        $sheet = new EventResultsSheet(
            id: Uuid::uuid7(),
            spreadsheetId: 'test-spreadsheet-unfinished-0123456789',
            organization: $organization,
            actingPlayer: $creator,
            timezone: 'America/Chicago',
            countryCode: 'us',
            addedAt: new DateTimeImmutable('2026-11-01 10:00:00'),
        );
        $this->entityManager->persist($sheet);
        $this->entityManager->flush();
        $this->sheetId = $sheet->id->toString();
    }

    public function testAnUnfinishedTeamIsImportedWithItsPiecesAndTheLimitAsTimeSpent(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Out of Time,,3,412,,,,,,,,,,,');

        self::assertSame('imported', $this->tab()['status']);
        self::assertSame([
            'Out of Time' => ['seconds_to_solve' => null, 'pieces_placed' => 412, 'seconds_spent' => 7200, 'finished_later_seconds' => null, 'player_id' => null, 'created_via' => 'event_import', 'has_round' => true],
            'Puzzle Pals' => ['seconds_to_solve' => 4213, 'pieces_placed' => null, 'seconds_spent' => null, 'finished_later_seconds' => null, 'player_id' => null, 'created_via' => 'event_import', 'has_round' => true],
        ], $this->results());
    }

    public function testAllPiecesPlacedKeepANewTabOutAndCreateNothing(): void
    {
        $logs = $this->captureLogs();

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Out of Time,,3,500,,,,,,,,,,,');

        $tab = $this->tab();
        self::assertSame('problem', $tab['status']);
        self::assertIsString($tab['problems']);
        self::assertStringContainsString('pieces_placed_out_of_range', $tab['problems']);
        self::assertStringContainsString('Out of Time: 500 / 500', $tab['problems']);
        self::assertNull($tab['competition_id']);
        self::assertSame([], $this->results());
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM competition WHERE name = 'Maple Street Puzzle Night'"));
        self::assertCount(1, $this->warnings($logs));
    }

    public function testAnImportedTabWhosePiecesStopFittingChangesNothing(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Out of Time,,3,412,,,,,,,,,,,');
        $before = $this->results();

        $this->apply($this->eventRow('Puzzle Pals', '1:05:00'), 'Out of Time,,3,612,,,,,,,,,,,');

        self::assertSame('problem', $this->tab()['status']);
        self::assertSame($before, $this->results(), 'Not even the corrected time of the other team - half a tab is never applied');
    }

    public function testUnfinishedTeamsWithoutATimeLimitKeepTheTabOutAndAnImportedOneKeepsItsResults(): void
    {
        $logs = $this->captureLogs();
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $before = $this->results();

        $withoutLimit = str_replace(',120,', ',,', $this->eventRow('Puzzle Pals', '1:10:13'));
        $this->apply($withoutLimit, 'Edge Lords,,3,412,,,,,,,,,,,');
        $this->apply($withoutLimit, 'Edge Lords,,3,412,,,,,,,,,,,');

        $tab = $this->tab();
        self::assertSame('problem', $tab['status']);
        self::assertIsString($tab['problems']);
        self::assertStringContainsString('unfinished_without_time_limit', $tab['problems']);
        self::assertSame($before, $this->results());
        // Once per change, not every run
        self::assertCount(1, $this->warnings($logs));
    }

    public function testATeamMovesBetweenFinishedAndUnfinishedInPlace(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $id = $this->idOf('Edge Lords');

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,,3,480,,,,,,,,,,,');
        self::assertSame($id, $this->idOf('Edge Lords'));
        self::assertSame(['seconds_to_solve' => null, 'pieces_placed' => 480, 'seconds_spent' => 7200], $this->kind('Edge Lords'));

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,,3,490,,,,,,,,,,,');
        self::assertSame(['seconds_to_solve' => null, 'pieces_placed' => 490, 'seconds_spent' => 7200], $this->kind('Edge Lords'));

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,2:01:00,3,,,,,,,,,,,,');
        self::assertSame($id, $this->idOf('Edge Lords'));
        self::assertSame(['seconds_to_solve' => 7260, 'pieces_placed' => null, 'seconds_spent' => null], $this->kind('Edge Lords'));
    }

    public function testAChangedTimeLimitIsTheTimeSpentOfItsUnfinishedTeams(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Out of Time,,3,412,,,,,,,,,,,');

        $this->apply(str_replace(',120,', ',90,', $this->eventRow('Puzzle Pals', '1:10:13')), 'Out of Time,,3,412,,,,,,,,,,,');

        self::assertSame(5400, $this->kind('Out of Time')['seconds_spent']);
        self::assertSame(90, $this->database->fetchOne(
            'SELECT cr.minutes_limit FROM competition_round cr JOIN event_results_sheet_tab t ON t.competition_id = cr.competition_id WHERE t.gid = :gid',
            ['gid' => self::GID],
        ));
    }

    public function testAClaimedTeamThatTurnsOutUnfinishedKeepsItsPeopleAndItsProgressionIsRecalculated(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $id = $this->idOf('Edge Lords');
        $this->messageBus->dispatch(new ClaimEventResultSlot($id, PlayerFixture::PLAYER_WITH_STRIPE));
        $this->entityManager->clear();

        $async = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $async);
        $async->reset();

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,,3,450,,,,,,,,,,,');

        self::assertSame(['seconds_to_solve' => null, 'pieces_placed' => 450, 'seconds_spent' => 7200], $this->kind('Edge Lords'));
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $id]));

        $sent = array_map(static fn (Envelope $envelope): object => $envelope->getMessage(), array_values($async->getSent()));
        $xp = array_values(array_filter($sent, static fn (object $message): bool => $message instanceof RecalculateXpChainForSolve));
        $badges = array_values(array_filter($sent, static fn (object $message): bool => $message instanceof RecalculateBadgesForPlayer));
        self::assertCount(1, $xp);
        self::assertSame($id, $xp[0]->solvingTimeId);
        self::assertCount(1, $badges);

        // The same sheet again changes nothing - nothing is recalculated
        $async->reset();
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13'), 'Edge Lords,,3,450,,,,,,,,,,,');
        self::assertSame([], $async->getSent());
    }

    private function apply(string ...$rows): void
    {
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab(self::GID, 'Maple Street 10/5', implode("\n", [self::HEADER, ...$rows])));

        $this->messageBus->dispatch(new ApplyEventResultsTab($this->sheetId, $event));
        $this->entityManager->clear();
    }

    private function eventRow(string $name, string $time): string
    {
        return sprintf(
            '"%s",%s,4,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,https://myspeedpuzzling.com/en/puzzle/%s,',
            $name,
            $time,
            PuzzleFixture::PUZZLE_500_01,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function tab(): array
    {
        $tab = $this->database->fetchAssociative('SELECT * FROM event_results_sheet_tab WHERE sheet_id = :sheet AND gid = :gid', [
            'sheet' => $this->sheetId,
            'gid' => self::GID,
        ]);
        self::assertIsArray($tab);

        return $tab;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function results(): array
    {
        $rows = $this->database->fetchAllAssociative(<<<SQL
SELECT team.name AS team_name, pst.seconds_to_solve, pst.pieces_placed, pst.seconds_spent, pst.finished_later_seconds, pst.player_id,
    pst.created_via, pst.competition_round_id IS NOT NULL AS has_round
FROM puzzle_solving_time pst
JOIN event_imported_result imported ON imported.solving_time_id = pst.id
JOIN puzzling_team team ON team.id = pst.puzzling_team_id
ORDER BY team.name
SQL);

        $results = [];

        foreach ($rows as $row) {
            $name = $row['team_name'];
            self::assertIsString($name);
            unset($row['team_name']);
            $results[$name] = $row;
        }

        return $results;
    }

    private function idOf(string $teamName): string
    {
        $id = $this->database->fetchOne(
            'SELECT pst.id FROM puzzle_solving_time pst JOIN puzzling_team team ON team.id = pst.puzzling_team_id JOIN event_imported_result i ON i.solving_time_id = pst.id WHERE team.name = :name',
            ['name' => $teamName],
        );
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function kind(string $teamName): array
    {
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, pieces_placed, seconds_spent FROM puzzle_solving_time WHERE id = :id',
            ['id' => $this->idOf($teamName)],
        );
        self::assertIsArray($row);

        return $row;
    }

    private function captureLogs(): TestHandler
    {
        $handler = new TestHandler();
        self::getContainer()->get('logger')->pushHandler($handler);

        return $handler;
    }

    /**
     * @return list<mixed>
     */
    private function warnings(TestHandler $logs): array
    {
        return array_values(array_filter(
            $logs->getRecords(),
            static fn ($record): bool => $record->level === Level::Warning && str_starts_with($record->message, 'Event results tab'),
        ));
    }
}
