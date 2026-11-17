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
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\MoveRoundToCompetition;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Query\CountCompetitionResults;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Services\RoundResults\SolvingTimeRoundResolver;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * docs/features/event-results-import/README.md D10: a pair at a teams event is imported as the pair's result and ranked
 * in the event's teams round - and nothing that re-derives a time's round later (the add/edit resolver, the reconcile
 * after a round change, the cron, a round move, claiming and releasing) takes it out. Made-up event and teams.
 */
final class ApplyEventResultsTabSmallerTeamsTest extends KernelTestCase
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
            spreadsheetId: 'test-spreadsheet-smaller-teams-0123',
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

    public function testAPairAtATeamsEventIsThePairsResultRankedInTheTeamsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Just Us Two,1:05:00,2,,,,,,,,,,,,', 'Edge Lords,1:12:04,3,,,,,,,,,,,,');

        $round = $this->round();
        self::assertSame('team', $round['category']);
        self::assertSame(4, (int) $round['team_size'], 'The pair says nothing about the size of the event\'s teams');

        $pair = $this->time('Just Us Two');
        self::assertSame('duo', $pair['puzzling_type']);
        self::assertSame(2, self::int($pair['puzzlers_count']));
        self::assertSame($round['id'], $pair['competition_round_id']);

        // The round page: ranked with the teams by time
        $results = $this->roundResults();
        self::assertSame(['Just Us Two', 'Puzzle Pals', 'Edge Lords'], array_map(static fn (RoundResult $result): null|string => $result->teamName, $results));
        self::assertSame([1, 2, 3], array_map(static fn (RoundResult $result): null|int => $result->rank, $results));
        self::assertCount(2, $results[0]->players, '"2 puzzlers"');

        self::assertSame(3, self::getContainer()->get(CountCompetitionResults::class)->perRound($this->competitionId())[$round['id']] ?? null);

        // The puzzle page: on the Pairs tab with its event, not on the Teams tab
        $solvers = self::getContainer()->get(GetPuzzleSolvers::class);
        $pairs = array_values(array_filter($solvers->duoByPuzzleId(PuzzleFixture::PUZZLE_500_01), static fn (object $group): bool => $group->timeId === $pair['id']));
        self::assertCount(1, $pairs);
        self::assertSame('Maple Street Puzzle Night', $pairs[0]->competitionName);
        self::assertTrue($pairs[0]->importedFromEvent);
        self::assertSame([], array_values(array_filter($solvers->teamByPuzzleId(PuzzleFixture::PUZZLE_500_01), static fn (object $group): bool => $group->timeId === $pair['id'])));
    }

    public function testNothingThatDerivesARoundLaterTakesThePairOutOfTheTeamsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Just Us Two,1:05:00,2,,,,,,,,,,,,');
        $roundId = $this->round()['id'];
        $pairId = self::string($this->time('Just Us Two')['id']);

        $reconciler = self::getContainer()->get(RoundResultsReconciler::class);
        $reconciler->reconcile(Uuid::fromString($this->competitionId()));
        $reconciler->reconcile();
        self::assertSame($roundId, $this->roundOf($pairId), 'The reconcile after a round change and the cron');

        $time = $this->entityManager->find(PuzzleSolvingTime::class, $pairId);
        self::assertNotNull($time);
        self::assertSame($roundId, self::getContainer()->get(SolvingTimeRoundResolver::class)->resolve($time)->round?->id->toString(), 'The add/edit handlers\' resolver');

        // Running the tab again re-applies an unchanged tab as unchanged, a changed one through the upsert
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Just Us Two,1:04:00,2,,,,,,,,,,,,');
        self::assertSame($roundId, $this->roundOf($pairId));
        self::assertSame(3840, self::int($this->time('Just Us Two')['seconds_to_solve']));
    }

    public function testATeamThatTurnsOutAPairAndBackKeepsItsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,3,,,,,,,,,,,,');
        $roundId = $this->round()['id'];
        $edgeLordsId = self::string($this->time('Edge Lords')['id']);

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,2,,,,,,,,,,,,');
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile();

        $edgeLords = $this->time('Edge Lords');
        self::assertSame($edgeLordsId, $edgeLords['id'], 'The same result, other anonymous people');
        self::assertSame('duo', $edgeLords['puzzling_type']);
        self::assertSame($roundId, $edgeLords['competition_round_id']);

        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Edge Lords,1:12:04,4,,,,,,,,,,,,');
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile();

        $edgeLords = $this->time('Edge Lords');
        self::assertSame('team', $edgeLords['puzzling_type']);
        self::assertSame(4, self::int($edgeLords['puzzlers_count']));
        self::assertSame($roundId, $edgeLords['competition_round_id']);
    }

    public function testAClaimedPairStaysInTheRoundAfterAClaimAndARelease(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Just Us Two,1:05:00,2,,,,,,,,,,,,');
        $roundId = $this->round()['id'];
        $pairId = self::string($this->time('Just Us Two')['id']);
        $reconciler = self::getContainer()->get(RoundResultsReconciler::class);

        $this->messageBus->dispatch(new ClaimEventResultSlot($pairId, PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();
        $reconciler->reconcile();
        self::assertSame($roundId, $this->roundOf($pairId));
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $pairId]));

        $regular = array_values(array_filter($this->roundResults(), static fn (RoundResult $result): bool => $result->timeId === $pairId))[0];
        self::assertTrue($regular->containsPlayer(PlayerFixture::PLAYER_REGULAR));

        $this->messageBus->dispatch(new ReleaseEventResultSlot($pairId, PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();
        $reconciler->reconcile();
        self::assertSame($roundId, $this->roundOf($pairId));
        self::assertNull($this->database->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $pairId]));
    }

    public function testThePairMovesWithItsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'), 'Just Us Two,1:05:00,2,,,,,,,,,,,,');
        $roundId = $this->round()['id'];
        $sourceId = $this->competitionId();
        $pairId = self::string($this->time('Just Us Two')['id']);

        // Another imported event of the organization, on another puzzle
        $this->applyTab('616161', [str_replace(
            ['Maple Street Puzzle Night', PuzzleFixture::PUZZLE_500_01],
            ['Maple Street Puzzle Night Two', PuzzleFixture::PUZZLE_500_02],
            $this->eventRow('Other Team', '1:20:00', '4'),
        )]);
        $targetId = self::string($this->database->fetchOne("SELECT id FROM competition WHERE name = 'Maple Street Puzzle Night Two'"));

        $this->messageBus->dispatch(new MoveRoundToCompetition(
            roundId: $roundId,
            competitionId: $sourceId,
            targetCompetitionId: $targetId,
            actingPlayerId: PlayerFixture::PLAYER_ADMIN,
        ));
        $this->entityManager->clear();
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile();

        self::assertSame(
            ['competition_id' => $targetId, 'competition_round_id' => $roundId],
            $this->database->fetchAssociative('SELECT competition_id, competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $pairId]),
        );
    }

    /**
     * Only an imported result keeps its round: anybody's own pair time on the puzzle of a teams round is in no round,
     * exactly as before.
     */
    public function testAnOrdinaryPairTimeIsStillLeftOutOfATeamsRound(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:10:13', '4'));
        $roundId = $this->round()['id'];
        $pairId = self::string($this->time('Puzzle Pals')['id']);

        // An ordinary result put into the round by hand (a stale link) - the reconcile takes it out
        $ordinaryId = self::string($this->database->fetchOne(
            "SELECT id FROM puzzle_solving_time WHERE puzzling_type = 'duo' AND created_via IS DISTINCT FROM 'event_import' LIMIT 1",
        ));
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET competition_id = :competitionId, competition_round_id = :roundId, puzzle_id = :puzzleId WHERE id = :id',
            ['competitionId' => $this->competitionId(), 'roundId' => $roundId, 'puzzleId' => PuzzleFixture::PUZZLE_500_01, 'id' => $ordinaryId],
        );

        self::getContainer()->get(RoundResultsReconciler::class)->reconcile(Uuid::fromString($this->competitionId()));

        self::assertNull($this->roundOf($ordinaryId));
        self::assertSame($roundId, $this->roundOf($pairId));
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

    private function roundOf(string $timeId): null|string
    {
        $roundId = $this->database->fetchOne('SELECT competition_round_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertTrue($roundId === null || is_string($roundId));

        return $roundId;
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
        $this->applyTab(self::GID, array_values($rows));
    }

    /**
     * @param list<string> $rows
     */
    private function applyTab(string $gid, array $rows): void
    {
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab($gid, 'Maple Street ' . $gid, implode("\n", [self::HEADER, ...$rows])));

        $this->messageBus->dispatch(new ApplyEventResultsTab($this->sheetId, $event));
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

    private function competitionId(): string
    {
        return self::string($this->database->fetchOne("SELECT id FROM competition WHERE name = 'Maple Street Puzzle Night'"));
    }

    /**
     * @return array{id: string, category: string, team_size: null|int|string}
     */
    private function round(): array
    {
        $round = $this->database->fetchAssociative(
            'SELECT id, category, team_size FROM competition_round WHERE competition_id = :id',
            ['id' => $this->competitionId()],
        );
        self::assertIsArray($round);
        self::assertIsString($round['id']);
        self::assertIsString($round['category']);

        /** @var array{id: string, category: string, team_size: null|int|string} $round */
        return $round;
    }

    /**
     * @return array<string, mixed>
     */
    private function time(string $teamName): array
    {
        $time = $this->database->fetchAssociative(<<<SQL
SELECT pst.*
FROM puzzle_solving_time pst
JOIN event_imported_result imported ON imported.solving_time_id = pst.id
JOIN puzzling_team team ON team.id = pst.puzzling_team_id
WHERE team.name = :name
SQL, ['name' => $teamName]);
        self::assertIsArray($time);

        return $time;
    }
}
