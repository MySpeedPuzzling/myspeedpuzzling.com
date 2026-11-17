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
use SpeedPuzzling\Web\Exceptions\EventResultClaimRefused;
use SpeedPuzzling\Web\Exceptions\PuzzleResultNotFound;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\DeletePlayer;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Message\RemoveEventResultClaim;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetEventResultClaims;
use SpeedPuzzling\Web\Query\GetPlayerSolvedPuzzles;
use SpeedPuzzling\Web\Query\GetPuzzleResultDetail;
use SpeedPuzzling\Web\Query\GetPuzzleSolvers;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Results\EditionRoundDetail;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Services\PuzzleStatisticsCalculator;
use SpeedPuzzling\Web\Services\RoundResults\RoundResultsReconciler;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\AppliedEventResultsTab;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblemCode;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * docs/features/event-results-import/solo-and-relays.md §2: a relay tab (`Link to MySpeedPuzzling 2`) - every team
 * solved both puzzles at the same time and only its total is known. One result per puzzle, linked by a relay_result,
 * kept off the puzzle's pages, ranked by the total on the event's round, claimed and released together. Made-up events
 * and teams.
 */
final class ApplyEventResultsTabRelayTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Link to MySpeedPuzzling 2,Puzzle Link- manufacturer';
    private const string GID = '828282';
    private const string FIRST = PuzzleFixture::PUZZLE_500_01;
    private const string SECOND = PuzzleFixture::PUZZLE_500_02;

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
            spreadsheetId: 'test-spreadsheet-relay-0123456789',
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

    public function testARelayTeamIsOneResultPerPuzzleLinkedByTheRelaysTotal(): void
    {
        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'), 'Edge Lords,1:30:01,3,,,,,,,,,,,,,');

        self::assertSame(EventTabStatus::Imported, $applied->status);
        self::assertSame(2, $applied->teamsAdded);

        // One teams round with both puzzles
        $round = $this->round();
        self::assertSame('team', $round['category']);
        self::assertEqualsCanonicalizing([self::FIRST, self::SECOND], $this->database->fetchFirstColumn('SELECT puzzle_id FROM competition_round_puzzle WHERE round_id = :id', ['id' => $round['id']]));

        $legs = $this->legs('Puzzle Pals');
        self::assertCount(2, $legs);
        self::assertSame([self::FIRST, self::SECOND], array_column($legs, 'puzzle_id'));
        self::assertSame(5025, $this->number('SELECT seconds FROM relay_result WHERE id = :id', ['id' => $legs[0]['relay_result_id']]));

        foreach ($legs as $leg) {
            self::assertNull($leg['seconds_to_solve'], 'No time of its own');
            self::assertNull($leg['pieces_placed'], 'A solve, like a relax one');
            self::assertNull($leg['player_id']);
            self::assertSame('team', $leg['puzzling_type']);
            self::assertSame($round['id'], $leg['competition_round_id']);
            self::assertSame('event_import', $leg['created_via']);
            // The same relay, the same anonymous people
            self::assertSame($legs[0]['relay_result_id'], $leg['relay_result_id']);
            self::assertSame($legs[0]['puzzling_team_id'], $leg['puzzling_team_id']);
            self::assertSame($legs[0]['team'], $leg['team']);
        }

        // The total split, the remainder on the first puzzle: time spent counts it once
        self::assertSame([2513, 2512], array_map(static fn (array $leg): int => (int) $leg['seconds_spent'], $legs));
        self::assertSame([0, 1], array_map($this->number(...), $this->database->fetchFirstColumn("SELECT leg::text FROM event_imported_result WHERE team_key = 'puzzle pals' ORDER BY leg")));

        // The reconcile never moves them (keepsItsRound())
        self::getContainer()->get(RoundResultsReconciler::class)->reconcile();
        self::assertSame([$round['id'], $round['id']], array_column($this->legs('Puzzle Pals'), 'competition_round_id'));
    }

    public function testTheRoundRanksTheRelayTeamsByTheirTotalOneRowEach(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:30:01', '4'), 'Edge Lords,1:23:45,3,,,,,,,,,,,,,', 'Jane Doe,1:40:00,1,,,,,,,,,,,,,');

        $round = $this->roundDetail();
        $getRoundResults = self::getContainer()->get(GetRoundResults::class);

        // No puzzle of the round lists them on its own
        self::assertSame([], $getRoundResults->forRound($round, null));

        $relays = $getRoundResults->relaysForRound($round, null);
        self::assertSame(['Edge Lords', 'Puzzle Pals', 'Jane Doe'], array_map(static fn (RoundResult $result): null|string => $result->teamName, $relays));
        self::assertSame([5025, 5401, 6000], array_map(static fn (RoundResult $result): null|int => $result->seconds, $relays));
        self::assertSame([1, 2, 3], array_map(static fn (RoundResult $result): null|int => $result->rank, $relays));
        self::assertTrue($relays[0]->fromOrganizer);
        self::assertNotNull($relays[0]->relayResultId);
        // The team's first result (the lowest id) is the one claims go through
        self::assertSame($this->firstId('Edge Lords'), $relays[0]->timeId);
        self::assertCount(3, $relays[0]->players);
        // A single person's relay: one spot, by the first result
        self::assertSame('s:' . $this->firstId('Jane Doe'), $relays[2]->players[0]->anonymousSlot);
    }

    public function testTheRelayIsKeptOffThePuzzlesPages(): void
    {
        $solvers = self::getContainer()->get(GetPuzzleSolvers::class);
        $statistics = self::getContainer()->get(PuzzleStatisticsCalculator::class);
        $countsBefore = $solvers->untimedCountsByPuzzleId(self::FIRST);
        $statisticsBefore = $statistics->calculateForPuzzle(Uuid::fromString(self::FIRST));

        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'));

        // Neither a relax solve of the tab nor "Completed N×" of the puzzle
        self::assertSame($countsBefore, $solvers->untimedCountsByPuzzleId(self::FIRST));
        self::assertEquals($statisticsBefore, $statistics->calculateForPuzzle(Uuid::fromString(self::FIRST)));

        // The result detail of the puzzle has no such result
        $this->expectException(PuzzleResultNotFound::class);
        self::getContainer()->get(GetPuzzleResultDetail::class)->byTimeId($this->legs('Puzzle Pals')[0]['id'], null);
    }

    public function testACorrectedTotalChangesTheSharesOfTheSameResults(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'));
        $ids = array_column($this->legs('Puzzle Pals'), 'id');

        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:00:01', '4'));
        self::assertSame(1, $applied->teamsUpdated);

        $legs = $this->legs('Puzzle Pals');
        self::assertSame($ids, array_column($legs, 'id'));
        self::assertSame([1801, 1800], array_map(static fn (array $leg): int => (int) $leg['seconds_spent'], $legs));
        self::assertSame(3601, $this->number('SELECT seconds FROM relay_result WHERE id = :id', ['id' => $legs[0]['relay_result_id']]));

        // Unchanged: nothing to apply
        self::assertTrue($this->apply($this->eventRow('Puzzle Pals', '1:00:01', '4'))->unchanged);
    }

    public function testATeamChangingSizeKeepsBothResultsOnOneTeam(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'));
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '3'));

        $legs = $this->legs('Puzzle Pals');
        self::assertSame(3, (int) $legs[0]['puzzlers_count']);
        self::assertSame($legs[0]['puzzling_team_id'], $legs[1]['puzzling_team_id']);
        self::assertSame($legs[0]['team'], $legs[1]['team']);

        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '1'));
        $legs = $this->legs('Puzzle Pals');
        self::assertSame(['solo', 'solo'], array_column($legs, 'puzzling_type'));
        self::assertNull($legs[1]['puzzling_team_id']);
    }

    public function testATeamTakenOffTheSheetGoesWithBothResultsAndItsRelay(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'), 'Edge Lords,1:30:01,3,,,,,,,,,,,,,');
        $relayId = $this->legs('Edge Lords')[0]['relay_result_id'];

        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'));

        self::assertSame(1, $applied->teamsRemoved);
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM event_imported_result WHERE team_key = 'edge lords'"));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzle_solving_time WHERE relay_result_id = :id', ['id' => $relayId]));
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM relay_result WHERE id = :id', ['id' => $relayId]));
    }

    public function testARelayWaitsForItsSecondPuzzle(): void
    {
        $missing = '018d0003-0000-0000-0000-0000000fffff';
        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4', $missing));

        self::assertSame(EventTabStatus::WaitingForPuzzle, $applied->status);
        self::assertSame([$missing], $this->problemDetails(EventTabProblemCode::PuzzleNotFound));
        self::assertFalse($this->database->fetchOne("SELECT 1 FROM competition WHERE name = 'Maple Street Relay Night'"));
    }

    public function testATabThatTurnsIntoARelayOrBackAfterTheImportIsForAPerson(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4'));

        $applied = $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '4', ''));

        self::assertSame(EventTabStatus::Problem, $applied->status);
        self::assertContains(EventTabProblemCode::PuzzleChangedAfterImport->value, $this->problemCodes());
        self::assertCount(2, $this->legs('Puzzle Pals'), 'Nothing changed');
    }

    public function testClaimingARelayTeamClaimsBothResultsAndReleasingReleasesBoth(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '3'));
        [$first, $second] = array_column($this->legs('Puzzle Pals'), 'id');
        $firstId = min($first, $second);
        $secondId = max($first, $second);

        // Opened from the second result: the relay's first result is the one claimed (one lock, one log line)
        $context = self::getContainer()->get(GetEventResultClaims::class)->context($secondId, PlayerFixture::PLAYER_REGULAR);
        self::assertNotNull($context);
        self::assertSame($firstId, $context->timeId);
        self::assertTrue($context->isRelay());
        self::assertSame(5025, $context->relayTotalSeconds);
        self::assertCount(2, $context->relayPuzzleNames);
        self::assertFalse($context->asksFirstTry());
        self::assertSame([], $context->sameResultCandidates);

        $this->messageBus->dispatch(new ClaimEventResultSlot($firstId, PlayerFixture::PLAYER_REGULAR, firstTry: true));
        $this->entityManager->clear();

        $legs = $this->legs('Puzzle Pals');
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR], array_column($legs, 'player_id'));
        self::assertSame($legs[0]['puzzling_team_id'], $legs[1]['puzzling_team_id']);
        self::assertSame($legs[0]['team'], $legs[1]['team']);
        self::assertStringContainsString(PlayerFixture::PLAYER_REGULAR, (string) $legs[0]['team']);
        // A relay is never a first try
        self::assertSame([false, false], array_column($legs, 'first_attempt'));
        self::assertSame(1, $this->number("SELECT COUNT(*) FROM result_claim_log WHERE player_id = :player AND action = 'claimed'", ['player' => PlayerFixture::PLAYER_REGULAR]));

        // Both on the profile, one relay each: the total, two puzzles, the round
        $team = self::getContainer()->get(GetPlayerSolvedPuzzles::class)->teamByPlayerId(PlayerFixture::PLAYER_REGULAR);
        $relays = array_values(array_filter($team, static fn ($solved): bool => $solved->isRelay()));
        self::assertCount(2, $relays);
        self::assertSame([5025, 5025], [$relays[0]->relayTotalSeconds, $relays[1]->relayTotalSeconds]);
        self::assertSame([2, 2], [$relays[0]->relayPuzzleCount, $relays[1]->relayPuzzleCount]);
        self::assertSame('teams', $relays[0]->relayRoundSlug);
        self::assertFalse($relays[0]->isRelax());

        // The second claimer: the same slot on both again, the gates once
        $this->messageBus->dispatch(new ClaimEventResultSlot($firstId, PlayerFixture::PLAYER_WITH_FAVORITES));
        $this->entityManager->clear();
        $legs = $this->legs('Puzzle Pals');
        self::assertSame($legs[0]['team'], $legs[1]['team']);
        self::assertSame($legs[0]['puzzling_team_id'], $legs[1]['puzzling_team_id']);

        // Release: both results, the same new anonymous slot - still one team
        $this->messageBus->dispatch(new ReleaseEventResultSlot($firstId, PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();
        $legs = $this->legs('Puzzle Pals');
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_FAVORITES], array_column($legs, 'player_id'));
        self::assertSame($legs[0]['team'], $legs[1]['team']);
        self::assertSame($legs[0]['puzzling_team_id'], $legs[1]['puzzling_team_id']);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_REGULAR, (string) $legs[0]['team']);

        // "Not on our team" by the organizer's creator: the last claimer goes, the relay is nobody's again
        $this->messageBus->dispatch(new RemoveEventResultClaim($firstId, PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE));
        $this->entityManager->clear();
        $legs = $this->legs('Puzzle Pals');
        self::assertSame([null, null], array_column($legs, 'player_id'));
        self::assertSame($legs[0]['puzzling_team_id'], $legs[1]['puzzling_team_id']);
        self::assertSame(1, $this->number("SELECT COUNT(*) FROM result_claim_log WHERE player_id = :player AND action = 'removed'", ['player' => PlayerFixture::PLAYER_WITH_FAVORITES]), 'One removal, not one per puzzle');
    }

    public function testASameResultIsRefusedForARelay(): void
    {
        $this->apply($this->eventRow('Puzzle Pals', '1:23:45', '3'));
        $firstId = $this->firstId('Puzzle Pals');

        $this->expectException(EventResultClaimRefused::class);
        $this->messageBus->dispatch(new ClaimEventResultSlot($firstId, PlayerFixture::PLAYER_REGULAR, sameResultTimeId: Uuid::uuid7()->toString()));
    }

    public function testASingleRelayClaimedAndTheAccountDeletedReleasesBoth(): void
    {
        $this->apply($this->eventRow('Jane Doe', '1:23:45', '1'));
        $firstId = $this->firstId('Jane Doe');

        $this->messageBus->dispatch(new ClaimEventResultSlot($firstId, PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();
        self::assertSame([PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_REGULAR], array_column($this->legs('Jane Doe'), 'player_id'));

        $this->messageBus->dispatch(new DeletePlayer(PlayerFixture::PLAYER_REGULAR));
        $this->entityManager->clear();

        $legs = $this->legs('Jane Doe');
        self::assertCount(2, $legs, 'The event\'s results stay');
        self::assertSame([null, null], array_column($legs, 'player_id'));
    }

    /**
     * The relay's first result (the lowest id) - claims go through it.
     */
    private function firstId(string $teamName): string
    {
        $ids = array_column($this->legs($teamName), 'id');
        self::assertNotSame([], $ids);
        sort($ids);

        return $ids[0];
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function number(mixed $sqlOrValue, array $parameters = []): int
    {
        $value = is_string($sqlOrValue) && str_starts_with($sqlOrValue, 'SELECT') ? $this->database->fetchOne($sqlOrValue, $parameters) : $sqlOrValue;
        self::assertTrue(is_int($value) || is_numeric($value));

        return (int) $value;
    }

    private function roundDetail(): EditionRoundDetail
    {
        $roundId = $this->round()['id'];

        foreach (self::getContainer()->get(GetEditionRounds::class)->forCompetition($this->competitionId()) as $round) {
            if ($round->id === $roundId) {
                return $round;
            }
        }

        self::fail('No round');
    }

    /**
     * @return list<string>
     */
    private function problemCodes(): array
    {
        return array_map(static fn (array $problem): string => $problem['code'], $this->problems());
    }

    /**
     * @return list<null|string>
     */
    private function problemDetails(EventTabProblemCode $code): array
    {
        return array_values(array_map(
            static fn (array $problem): null|string => $problem['detail'],
            array_filter($this->problems(), static fn (array $problem): bool => $problem['code'] === $code->value),
        ));
    }

    /**
     * @return list<array{code: string, detail: null|string}>
     */
    private function problems(): array
    {
        $json = $this->database->fetchOne('SELECT problems FROM event_results_sheet_tab WHERE gid = :gid', ['gid' => self::GID]);
        self::assertIsString($json);

        /** @var list<array{code: string, detail: null|string}> $problems */
        $problems = json_decode($json, true);

        return $problems;
    }

    private function apply(string ...$rows): AppliedEventResultsTab
    {
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab(self::GID, 'Maple Relay ' . self::GID, implode("\n", [self::HEADER, ...$rows])));

        $envelope = $this->messageBus->dispatch(new ApplyEventResultsTab($this->sheetId, $event));
        $this->entityManager->clear();

        $applied = $envelope->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(AppliedEventResultsTab::class, $applied);

        return $applied;
    }

    private function eventRow(string $name, string $time, string $size, string $secondPuzzleId = self::SECOND): string
    {
        return sprintf(
            '"%s",%s,%s,,Maple Street Relay Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,https://myspeedpuzzling.com/en/puzzle/%s,%s,',
            $name,
            $time,
            $size,
            self::FIRST,
            $secondPuzzleId === '' ? '' : 'https://myspeedpuzzling.com/en/puzzle/' . $secondPuzzleId,
        );
    }

    private function competitionId(): string
    {
        $id = $this->database->fetchOne("SELECT id FROM competition WHERE name = 'Maple Street Relay Night'");
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array{id: string, category: string}
     */
    private function round(): array
    {
        /** @var false|array{id: string, category: string} $round */
        $round = $this->database->fetchAssociative('SELECT id, category FROM competition_round WHERE competition_id = :id', ['id' => $this->competitionId()]);
        self::assertIsArray($round);

        return $round;
    }

    /**
     * The team's results in the order of the relay's puzzles.
     *
     * @return list<array{id: string, puzzle_id: string, relay_result_id: string, seconds_to_solve: null|int, pieces_placed: null|int, seconds_spent: null|int, player_id: null|string, puzzling_type: string, puzzlers_count: int, puzzling_team_id: null|string, team: null|string, competition_round_id: string, created_via: string, first_attempt: bool}>
     */
    private function legs(string $teamName): array
    {
        /** @var list<array{id: string, puzzle_id: string, relay_result_id: string, seconds_to_solve: null|int, pieces_placed: null|int, seconds_spent: null|int, player_id: null|string, puzzling_type: string, puzzlers_count: int, puzzling_team_id: null|string, team: null|string, competition_round_id: string, created_via: string, first_attempt: bool}> $legs */
        $legs = $this->database->fetchAllAssociative(<<<SQL
SELECT pst.id, pst.puzzle_id, pst.relay_result_id, pst.seconds_to_solve, pst.pieces_placed, pst.seconds_spent, pst.player_id,
    pst.puzzling_type, pst.puzzlers_count, pst.puzzling_team_id, pst.team::text AS team, pst.competition_round_id, pst.created_via, pst.first_attempt
FROM puzzle_solving_time pst
JOIN event_imported_result imported ON imported.solving_time_id = pst.id
WHERE imported.name = :name
ORDER BY imported.leg
SQL, ['name' => $teamName]);

        return $legs;
    }
}
