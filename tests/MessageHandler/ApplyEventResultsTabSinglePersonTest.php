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
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use SpeedPuzzling\Web\Value\EventResultsImport\AppliedEventResultsTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/event-results-import/solo-and-relays.md §1: a single person (Team Size 1) at a Teams or Pairs event, and
 * every row of a Solo event, is a solo result without its player (shape A) - the sheet's name on the imported row, ranked
 * in the tab's one round. Made-up events and people.
 */
final class ApplyEventResultsTabSinglePersonTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Puzzle Link- manufacturer';
    private const string GID = '717171';

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
            spreadsheetId: 'test-spreadsheet-single-person-0123',
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

    public function testASinglePersonAtATeamsEventIsASoloResultWithoutItsPlayerRankedInTheTeamsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,1,,,,,,,,,,,,', 'Edge Lords,1:12:04,3,,,,,,,,,,,,');

        $round = $this->round();
        self::assertSame('team', $round['category']);
        self::assertSame(4, (int) $round['team_size'], 'A single person says nothing about the size of the event\'s teams');

        $jane = $this->time('Jane Doe');
        self::assertSame('solo', $jane['puzzling_type']);
        self::assertSame(1, self::int($jane['puzzlers_count']));
        self::assertNull($jane['player_id']);
        self::assertNull($jane['team']);
        self::assertNull($jane['puzzling_team_id']);
        self::assertSame('event_import', $jane['created_via']);
        self::assertSame($round['id'], $jane['competition_round_id']);

        $results = $this->roundResults();
        self::assertSame(['Jane Doe', 'Puzzle Pals', 'Edge Lords'], array_map(static fn (RoundResult $result): null|string => $result->teamName, $results));
        self::assertSame([1, 2, 3], array_map(static fn (RoundResult $result): null|int => $result->rank, $results));
        self::assertCount(1, $results[0]->players);
        self::assertSame('s:' . self::string($jane['id']), $results[0]->players[0]->anonymousSlot);

        // The reconcile never takes it out of the teams round (keepsItsRound())
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile();
        self::assertSame($round['id'], $this->time('Jane Doe')['competition_round_id']);
    }

    public function testTheSheetsNameIsKeptForEveryRow(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,1,,,,,,,,,,,,');

        self::assertSame(
            ['Jane Doe', 'Puzzle Pals'],
            $this->database->fetchFirstColumn('SELECT name FROM event_imported_result ORDER BY name'),
        );
    }

    public function testASinglePersonAtAPairsEventToo(): void
    {
        $this->apply($this->eventRow('Two of Us', '24:48', '2', 'Pairs'), 'Jane Doe,25:10,1,,,,,,,,,,,,');

        self::assertSame('duo', $this->round()['category']);
        self::assertSame('solo', $this->time('Jane Doe')['puzzling_type']);
        self::assertSame($this->round()['id'], $this->time('Jane Doe')['competition_round_id']);
    }

    public function testASoloEventIsAnIndividualRoundOfSinglePeople(): void
    {
        $applied = $this->apply($this->eventRow('Jane Doe', '45:10', '1', 'Solo'), 'John Roe,,1,312,,,,,,,,,,,');

        self::assertSame(EventTabStatus::Imported, $applied->status);
        self::assertSame(2, $applied->teamsAdded);

        $round = $this->round();
        self::assertSame('solo', $round['category']);
        self::assertNull($round['team_size']);
        self::assertSame('Individual', $round['name']);

        $john = $this->time('John Roe');
        self::assertSame('solo', $john['puzzling_type']);
        self::assertNull($john['seconds_to_solve']);
        self::assertSame(312, self::int($john['pieces_placed']));
        self::assertSame(90 * 60, self::int($john['seconds_spent']));

        $results = $this->roundResults();
        self::assertSame(['Jane Doe', 'John Roe'], array_map(static fn (RoundResult $result): null|string => $result->teamName, $results));
    }

    public function testATeamThatTurnsIntoOnePersonAndBackIsTheSameResult(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $edgeLordsId = self::string($this->time('Edge Lords')['id']);
        $teamId = self::string($this->time('Edge Lords')['puzzling_team_id']);

        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Edge Lords,1:12:04,1,,,,,,,,,,,,');
        self::assertSame(1, $applied->teamsUpdated);

        $edgeLords = $this->time('Edge Lords');
        self::assertSame($edgeLordsId, $edgeLords['id']);
        self::assertSame('solo', $edgeLords['puzzling_type']);
        self::assertNull($edgeLords['team']);
        self::assertNull($edgeLords['puzzling_team_id']);
        // The anonymous team it had is nobody's, with no result - left like any empty unnamed leftover
        self::assertSame($teamId, $this->database->fetchOne('SELECT id FROM puzzling_team WHERE id = :id', ['id' => $teamId]));

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Edge Lords,1:12:04,2,,,,,,,,,,,,');

        $edgeLords = $this->time('Edge Lords');
        self::assertSame($edgeLordsId, $edgeLords['id']);
        self::assertSame('duo', $edgeLords['puzzling_type']);
        self::assertSame('Edge Lords', $this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $edgeLords['puzzling_team_id']]));
    }

    public function testASinglePersonsNameFollowsTheSheet(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'jane doe,1:05:00,1,,,,,,,,,,,,');
        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,1,,,,,,,,,,,,');

        self::assertSame(1, $applied->teamsUpdated);
        self::assertSame('Jane Doe', $this->database->fetchOne("SELECT name FROM event_imported_result WHERE team_key = 'jane doe'"));
    }

    public function testAClaimedSingleResultIsNeverTouchedByTheImport(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,1,,,,,,,,,,,,');
        $janeId = self::string($this->time('Jane Doe')['id']);

        $this->messageBus->dispatch(new ClaimEventResultSlot($janeId, PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        // Another size: kept as it was, said once
        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,3,,,,,,,,,,,,');
        self::assertSame(EventTabStatus::Imported, $applied->status);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->time('Jane Doe')['player_id']);
        self::assertSame('solo', $this->time('Jane Doe')['puzzling_type']);
        self::assertContains(EventTabProblemCode::ClaimedTeamChangedSize->value, $this->problemCodes());

        // Taken off the sheet: kept, named by the sheet's name
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'));
        self::assertSame($janeId, $this->time('Jane Doe')['id']);
        self::assertContains('Jane Doe', $this->problemDetails(EventTabProblemCode::ClaimedTeamRemovedFromSheet));
    }

    public function testAnUnclaimedSingleResultTakenOffTheSheetIsRemoved(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'), 'Jane Doe,1:05:00,1,,,,,,,,,,,,');
        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4', 'Teams'));

        self::assertSame(1, $applied->teamsRemoved);
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM event_imported_result WHERE team_key = 'jane doe'"));
    }

    /**
     * @return list<RoundResult>
     */
    private function roundResults(): array
    {
        $roundId = $this->round()['id'];

        foreach (self::getContainer()->get(GetEditionRounds::class)->forCompetition($this->competitionId()) as $round) {
            if ($round->id === $roundId) {
                return self::getContainer()->get(GetRoundResults::class)->forRound($round, null)[PuzzleFixture::PUZZLE_500_01] ?? [];
            }
        }

        self::fail('No round');
    }

    /**
     * @return list<string>
     */
    private function problemCodes(): array
    {
        $problems = json_decode(self::string($this->database->fetchOne('SELECT problems FROM event_results_sheet_tab WHERE gid = :gid', ['gid' => self::GID])), true);
        self::assertIsArray($problems);

        return array_values(array_map(static fn (mixed $problem): string => is_array($problem) && is_string($problem['code'] ?? null) ? $problem['code'] : '', $problems));
    }

    /**
     * @return list<null|string>
     */
    private function problemDetails(EventTabProblemCode $code): array
    {
        $problems = json_decode(self::string($this->database->fetchOne('SELECT problems FROM event_results_sheet_tab WHERE gid = :gid', ['gid' => self::GID])), true);
        self::assertIsArray($problems);

        $details = [];

        foreach ($problems as $problem) {
            if (is_array($problem) && ($problem['code'] ?? null) === $code->value) {
                $details[] = is_string($problem['detail'] ?? null) ? $problem['detail'] : null;
            }
        }

        return $details;
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

    private function apply(string ...$rows): AppliedEventResultsTab
    {
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab(self::GID, 'Maple Street ' . self::GID, implode("\n", [self::HEADER, ...$rows])));

        $envelope = $this->messageBus->dispatch(new ApplyEventResultsTab($this->sheetId, $event));
        $this->entityManager->clear();

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AppliedEventResultsTab::class, $applied);

        return $applied;
    }

    private function eventRow(string $name, string $time, string $size, string $type): string
    {
        return sprintf(
            '"%s",%s,%s,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,90,%s,https://myspeedpuzzling.com/en/puzzle/%s,',
            $name,
            $time,
            $size,
            $type,
            PuzzleFixture::PUZZLE_500_01,
        );
    }

    private function competitionId(): string
    {
        return self::string($this->database->fetchOne("SELECT id FROM competition WHERE name = 'Maple Street Puzzle Night'"));
    }

    /**
     * @return array{id: string, category: string, name: string, team_size: null|int|string}
     */
    private function round(): array
    {
        $round = $this->database->fetchAssociative(
            'SELECT id, category, name, team_size FROM competition_round WHERE competition_id = :id',
            ['id' => $this->competitionId()],
        );
        self::assertIsArray($round);
        self::assertIsString($round['id']);
        self::assertIsString($round['category']);

        /** @var array{id: string, category: string, name: string, team_size: null|int|string} $round */
        return $round;
    }

    /**
     * @return array<string, mixed>
     */
    private function time(string $name): array
    {
        $time = $this->database->fetchAssociative(<<<SQL
SELECT pst.*
FROM puzzle_solving_time pst
JOIN event_imported_result imported ON imported.solving_time_id = pst.id
WHERE imported.name = :name
SQL, ['name' => $name]);
        self::assertIsArray($time);

        return $time;
    }
}
