<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Services\EventResultsImport\EventResultsTabParser;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/event-results-import/README.md "Tab outcomes" + implementation-plan.md phase 3: every run is an upsert
 * - nothing twice, changes applied, unclaimed removed teams removed, nothing without its puzzle. Made-up event and teams.
 */
final class ApplyEventResultsTabHandlerTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Puzzle Link- manufacturer';
    private const string GID = '424242';

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
            spreadsheetId: 'test-spreadsheet-0123456789abcdef',
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

    public function testTheFirstRunCreatesTheEventItsRoundAndOneNobodysResultPerTeam(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');

        $competition = $this->competition();
        self::assertSame('Maple Street Puzzle Night', $competition['name']);
        self::assertSame('Maple Street Taproom, Springfield, MN', $competition['location']);
        self::assertSame('us', $competition['location_country_code']);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $competition['organization_id']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $competition['added_by_player_id']);
        self::assertNotNull($competition['approved_at'], 'An approved organization\'s team creates approved events');
        self::assertFalse((bool) $competition['is_draft']);
        self::assertSame('2026-10-05', substr(self::string($competition['date_from']), 0, 10));

        $round = $this->database->fetchAssociative(
            'SELECT * FROM competition_round WHERE competition_id = :id',
            ['id' => $competition['id']],
        );
        self::assertIsArray($round);
        self::assertSame('team', $round['category']);
        self::assertSame(120, self::int($round['minutes_limit']));
        self::assertSame(4, self::int($round['team_size']));
        // 7:00 PM in Minnesota (CDT, UTC-5)
        self::assertSame('2026-10-06 00:00:00', substr(self::string($round['starts_at']), 0, 19));
        self::assertSame(
            [PuzzleFixture::PUZZLE_500_01],
            $this->database->fetchFirstColumn('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :id', ['id' => $round['id']]),
        );

        $times = $this->times();
        self::assertCount(2, $times);
        self::assertSame(['Edge Lords', 'Puzzle Pals'], array_column($times, 'team_name'));
        self::assertSame([4324, 4213], array_map(self::int(...), array_column($times, 'seconds_to_solve')));
        self::assertSame([3, 4], array_map(self::int(...), array_column($times, 'puzzlers_count')));

        foreach ($times as $time) {
            self::assertNull($time['player_id'], 'Nobody\'s until claimed');
            self::assertSame('event_import', $time['created_via']);
            self::assertFalse((bool) $time['first_attempt']);
            self::assertSame($round['id'], $time['competition_round_id']);
            self::assertSame('2026-10-05', substr(self::string($time['finished_at']), 0, 10));
        }

        self::assertSame('imported', $this->tab()['status']);
    }

    public function testRunningAgainImportsNothingTwice(): void
    {
        $rows = [$this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,'];

        $this->apply(...$rows);
        $this->apply(...$rows);
        $this->apply(...$rows);

        self::assertCount(2, $this->times());
        self::assertSame(1, self::int($this->database->fetchOne('SELECT COUNT(*) FROM event_results_sheet_tab')));
        self::assertSame(1, self::int($this->database->fetchOne('SELECT COUNT(*) FROM competition WHERE name = :name', ['name' => 'Maple Street Puzzle Night'])));
    }

    public function testACorrectedTimeIsUpdated(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $timeId = $this->times()[0]['id'];

        $this->apply($this->eventRow('Puzzle Pals', '1:09:13', '4'));

        $times = $this->times();
        self::assertCount(1, $times);
        self::assertSame($timeId, $times[0]['id'], 'The same result, corrected');
        self::assertSame(4153, self::int($times[0]['seconds_to_solve']));
    }

    public function testATeamAddedLaterIsImported(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Late Arrivals,1:30:00,4,,,,,,,,,,,,');

        self::assertSame(['Late Arrivals', 'Puzzle Pals'], array_column($this->times(), 'team_name'));
    }

    public function testATeamRemovedFromTheSheetIsRemovedWhileNobodyClaimedIt(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $edgeLords = array_values(array_filter($this->times(), static fn (array $time): bool => $time['team_name'] === 'Edge Lords'))[0];

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));

        self::assertSame(['Puzzle Pals'], array_column($this->times(), 'team_name'));
        self::assertSame(1, self::int($this->database->fetchOne(
            'SELECT COUNT(*) FROM solving_time_graveyard WHERE solving_time_id = :id AND player_id IS NULL',
            ['id' => $edgeLords['id']],
        )), 'Buried like every deleted result');
    }

    /**
     * A team somebody claimed is a person's now: the organizer's corrected time still applies, taking it off the sheet
     * removes nothing (docs/features/event-results-import/README.md "Gates").
     */
    public function testAClaimedTeamFollowsACorrectedTimeAndStaysWhenTakenOffTheSheet(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $edgeLords = array_values(array_filter($this->times(), static fn (array $time): bool => $time['team_name'] === 'Edge Lords'))[0];
        self::assertIsString($edgeLords['id']);

        $this->messageBus->dispatch(new ClaimEventResultSlot($edgeLords['id'], PlayerFixture::PLAYER_WITH_STRIPE));
        $this->entityManager->clear();

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:11:00,3,,,,,,,,,,,,');

        $corrected = $this->database->fetchAssociative('SELECT seconds_to_solve, player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $edgeLords['id']]);
        self::assertSame(['seconds_to_solve' => 4260, 'player_id' => PlayerFixture::PLAYER_WITH_STRIPE], $corrected);

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));

        self::assertSame(['Edge Lords', 'Puzzle Pals'], array_column($this->times(), 'team_name'), 'A claimed team stays');
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $edgeLords['id']]));

        $tab = $this->tab();
        self::assertSame('imported', $tab['status']);
        self::assertIsString($tab['problems']);
        self::assertStringContainsString('claimed_team_removed_from_sheet', $tab['problems']);
    }

    public function testATabWithoutAPuzzleLinkWaitsAndCreatesNothing(): void
    {
        $this->apply(str_replace('https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, '', $this->eventRow('Puzzle Pals', '1:10:13', '4')));

        self::assertSame('waiting_for_puzzle', $this->tab()['status']);
        self::assertNull($this->tab()['competition_id']);
        self::assertSame([], $this->times());
        self::assertFalse($this->competition(allowMissing: true));
    }

    public function testALinkToAPuzzleThatDoesNotExistWaitsToo(): void
    {
        $this->apply(str_replace(PuzzleFixture::PUZZLE_500_01, '018d0003-0000-0000-0000-00000000dead', $this->eventRow('Puzzle Pals', '1:10:13', '4')));

        self::assertSame('waiting_for_puzzle', $this->tab()['status']);
        self::assertSame([], $this->times());
    }

    public function testOnceThePuzzleLinkIsThereTheNextRunImports(): void
    {
        $this->apply(str_replace('https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, '', $this->eventRow('Puzzle Pals', '1:10:13', '4')));
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));

        self::assertSame('imported', $this->tab()['status']);
        self::assertCount(1, $this->times());
    }

    public function testATabWithAProblemImportsNothing(): void
    {
        $this->apply(str_replace(',120,', ',two hours,', $this->eventRow('Puzzle Pals', '1:10:13', '4')));

        self::assertSame('problem', $this->tab()['status']);
        self::assertSame([], $this->times());
        self::assertFalse($this->competition(allowMissing: true));
    }

    public function testAnImportedEventThatGetsAProblemKeepsItsResults(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');

        // The organizer is half-way through editing - a broken tab must never read as "every team is gone"
        $this->apply(str_replace(',120,', ',two hours,', $this->eventRow('Puzzle Pals', '1:10:13', '4')));

        self::assertSame('problem', $this->tab()['status']);
        self::assertCount(2, $this->times());
    }

    public function testChangedEventDetailsAreAppliedAndTheAddressStays(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $slug = $this->competition()['slug'];

        $this->apply(str_replace('Maple Street Puzzle Night', 'Maple Street Puzzle Evening', $this->eventRow('Puzzle Pals', '1:10:13', '4')));

        self::assertSame('Maple Street Puzzle Evening', $this->competition()['name']);
        self::assertSame($slug, $this->competition()['slug']);
    }

    public function testADeletedEventIsNeverCreatedAgain(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $competitionId = self::string($this->competition()['id']);

        $this->database->executeStatement('DELETE FROM puzzle_solving_time WHERE competition_id = :id', ['id' => $competitionId]);
        $this->database->executeStatement('DELETE FROM competition_round_puzzle WHERE round_id IN (SELECT id FROM competition_round WHERE competition_id = :id)', ['id' => $competitionId]);
        $this->database->executeStatement('DELETE FROM competition_round WHERE competition_id = :id', ['id' => $competitionId]);
        $this->database->executeStatement('DELETE FROM competition WHERE id = :id', ['id' => $competitionId]);
        $this->entityManager->clear();

        $this->apply($this->eventRow('Puzzle Pals', '1:11:00', '4'));

        self::assertSame('detached', $this->tab()['status']);
        self::assertFalse($this->competition(allowMissing: true));
        self::assertSame([], $this->times());
    }

    public function testAPairsTabImportsPairs(): void
    {
        $row = str_replace([',Teams,', '"Puzzle Pals",1:10:13,4,'], [',Pairs,', '"Two of Us",0:24:48,2,'], $this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $this->apply($row);

        $times = $this->times();
        self::assertCount(1, $times);
        self::assertSame('duo', $times[0]['puzzling_type']);
        self::assertSame('duo', $this->database->fetchOne('SELECT category FROM competition_round'
            . ' WHERE competition_id = :id', ['id' => $this->competition()['id']]));
    }

    private static function int(mixed $value): int
    {
        self::assertTrue(is_int($value) || is_numeric($value));

        return (int) $value;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function apply(string ...$rows): void
    {
        $tab = (new EventResultsTabParser())->parse(implode("\n", [self::HEADER, ...$rows]));

        $this->messageBus->dispatch(new ApplyEventResultsTab($this->sheetId, self::GID, 'Maple Street 10/5', $tab));
        $this->entityManager->clear();
    }

    private function eventRow(string $name, string $time, string $size): string
    {
        return sprintf(
            '"%s",%s,%s,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,https://myspeedpuzzling.com/en/puzzle/%s,',
            $name,
            $time,
            $size,
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
     * @return ($allowMissing is true ? false|array<string, mixed> : array<string, mixed>)
     */
    private function competition(bool $allowMissing = false): false|array
    {
        $competition = $this->database->fetchAssociative(
            "SELECT * FROM competition WHERE name LIKE 'Maple Street Puzzle%'",
        );

        if ($allowMissing === false) {
            self::assertIsArray($competition);
        }

        return $competition;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function times(): array
    {
        return $this->database->fetchAllAssociative(<<<SQL
SELECT pst.*, team.name AS team_name
FROM puzzle_solving_time pst
JOIN event_imported_result imported ON imported.solving_time_id = pst.id
JOIN puzzling_team team ON team.id = pst.puzzling_team_id
ORDER BY team.name
SQL);
    }
}
