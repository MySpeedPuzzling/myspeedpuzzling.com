<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Entity\CompetitionParticipantRound;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Exceptions\ParticipantImportNotApplicable;
use SpeedPuzzling\Web\Exceptions\ParticipantImportPreviewStale;
use SpeedPuzzling\Web\Message\ApplyParticipantImport;
use SpeedPuzzling\Web\Results\ParticipantImportResult;
use SpeedPuzzling\Web\Services\ParticipantImport\ParticipantImportPlanner;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\MarketplaceEventFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use SpeedPuzzling\Web\Value\ParticipantImportRowData;
use SpeedPuzzling\Web\Value\ParticipantImportRows;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Synthetic data only. EDITION_OFFLINE_1 = Solo Round + Team Round; its only participant is the self-joined
 * "Sarah Williams".
 */
final class ApplyParticipantImportHandlerTest extends KernelTestCase
{
    private const string EVENT = CompetitionSeriesFixture::EDITION_OFFLINE_1;
    private const string SOLO = CompetitionSeriesFixture::ROUND_OFFLINE_SOLO;
    private const string TEAM = CompetitionSeriesFixture::ROUND_OFFLINE_TEAM;

    private MessageBusInterface $messageBus;
    private ParticipantImportPlanner $planner;
    private Connection $database;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->planner = self::getContainer()->get(ParticipantImportPlanner::class);
        $this->database = self::getContainer()->get(Connection::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFullSyncAppliesExactlyThePlan(): void
    {
        $oldCrew = $this->team('Old Crew');
        $readyTeam = $this->team('Ready Team');
        $alex = $this->participant('Alex Staying', [self::SOLO, self::TEAM]);
        $blake = $this->participant('Blake Leaving', [self::TEAM], $oldCrew);
        $hank = $this->participant('Hank Hidden', [self::TEAM], $oldCrew);
        $hank->softDelete(new DateTimeImmutable('2026-09-01'));
        $this->entityManager->flush();

        $rows = new ParticipantImportRows([
            self::row(2, 'Alex Staying', roundNames: 'Solo Round'),
            self::row(3, 'Nora New', roundNames: 'Solo Round, Team Round', teamsByRound: [self::TEAM => 'Fresh Five']),
        ], roundsMapped: true);

        $result = $this->apply($rows, ParticipantImportMode::Sync);

        self::assertSame(1, $result->added);
        self::assertSame(1, $result->updated);
        self::assertSame(2, $result->removed, 'Blake and the self-joined Sarah');
        self::assertSame(1, $result->roundEntriesRemoved);
        self::assertSame(1, $result->teamsRemoved);

        $this->entityManager->clear();

        // Alex left the Team Round
        self::assertSame([self::SOLO], $this->roundsOf($alex->id->toString()));

        // Blake is removed softly - restorable, still in his round
        self::assertNotNull($this->participantRow($blake->id->toString())['deleted_at']);
        self::assertSame([self::TEAM], $this->roundsOf($blake->id->toString()));

        // The self-joined Sarah became the organiser's first, so "I'm going" never silently restores her
        $sarah = $this->participantRow(MarketplaceEventFixture::PARTICIPANT_EDITION_SELLER_A);
        self::assertNotNull($sarah['deleted_at']);
        self::assertSame('imported', $sarah['source']);

        // Old Crew lost its last active member: deleted, the hidden removed Hank let go first
        self::assertFalse($this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $oldCrew->id->toString()]));
        self::assertNull($this->database->fetchOne(
            'SELECT team_id FROM competition_participant_round WHERE participant_id = :id',
            ['id' => $hank->id->toString()],
        ));

        // A team made in advance was empty before - it stays
        self::assertSame($readyTeam->id->toString(), $this->database->fetchOne('SELECT id FROM competition_team WHERE id = :id', ['id' => $readyTeam->id->toString()]));

        // Nora is new, in her new team
        self::assertSame('Fresh Five', $this->database->fetchOne(
            'SELECT ct.name FROM competition_participant_round cpr
             INNER JOIN competition_participant cp ON cp.id = cpr.participant_id
             INNER JOIN competition_team ct ON ct.id = cpr.team_id
             WHERE cp.competition_id = :event AND cp.name = :name',
            ['event' => self::EVENT, 'name' => 'Nora New'],
        ));

        // Planned again, the same file changes nothing any more
        $again = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);
        self::assertTrue($again->removals->isEmpty());
        self::assertSame(2, $again->count(ParticipantImportRowAction::Unchanged));
    }

    public function testStalePreviewWritesNothing(): void
    {
        $this->participant('Alex Staying', [self::SOLO]);
        $this->entityManager->flush();

        $rows = new ParticipantImportRows([self::row(2, 'Nora New', roundNames: 'Solo Round')], roundsMapped: true);
        $plan = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);

        // Somebody edits a participant between the preview and the confirm
        $this->database->executeStatement(
            "UPDATE competition_participant SET name = 'Alex Renamed' WHERE competition_id = :event AND name = 'Alex Staying'",
            ['event' => self::EVENT],
        );
        $before = $this->eventState();

        try {
            $this->messageBus->dispatch(new ApplyParticipantImport(self::EVENT, $rows, ParticipantImportMode::Sync->value, $plan->fingerprint));
            self::fail('A stale preview must not be applied');
        } catch (ParticipantImportPreviewStale) {
        }

        $this->entityManager->clear();
        self::assertSame($before, $this->eventState());
    }

    public function testAResultArrivingForSomebodyThePreviewRemovesMakesItStale(): void
    {
        $rows = new ParticipantImportRows([
            self::row(2, 'Jane Unconnected'),
            self::row(3, 'John Regular', externalId: 'EXT-001'),
            self::row(4, 'Secret Player'),
        ]);
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Sync);
        self::assertSame(['Michael Johnson'], array_column($plan->removals->selfJoined, 'name'));

        // Michael (PLAYER_WITH_FAVORITES) gets a result in the event after the preview
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :player WHERE id = :id',
            ['player' => PlayerFixture::PLAYER_WITH_FAVORITES, 'id' => PuzzleSolvingTimeFixture::TIME_11],
        );

        $this->expectException(ParticipantImportPreviewStale::class);
        $this->messageBus->dispatch(new ApplyParticipantImport(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Sync->value, $plan->fingerprint));
    }

    public function testAResultOfSomebodyElseDoesNotMakeThePreviewStale(): void
    {
        $rows = new ParticipantImportRows([
            self::row(2, 'Jane Unconnected'),
            self::row(3, 'John Regular', externalId: 'EXT-001'),
            self::row(4, 'Secret Player'),
        ]);
        $plan = $this->planner->plan(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Sync);

        // A new result of somebody the file keeps anyway
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET player_id = :player WHERE id = :id',
            ['player' => PlayerFixture::PLAYER_REGULAR, 'id' => PuzzleSolvingTimeFixture::TIME_11],
        );

        $result = $this->dispatch(new ApplyParticipantImport(CompetitionFixture::COMPETITION_WJPC_2024, $rows, ParticipantImportMode::Sync->value, $plan->fingerprint));

        self::assertSame(1, $result->removed);
        $this->entityManager->clear();
        self::assertNotNull($this->participantRow(CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED)['deleted_at']);
    }

    public function testFullSyncWithBlockersIsRefused(): void
    {
        $this->participant('Alex Staying', [self::SOLO]);
        $this->entityManager->flush();
        $before = $this->eventState();

        // The wrong file: nobody of it is on the site
        $rows = new ParticipantImportRows([self::row(2, 'Total Stranger')]);
        $plan = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Sync);
        self::assertNotSame([], $plan->syncBlockers);

        try {
            $this->messageBus->dispatch(new ApplyParticipantImport(self::EVENT, $rows, ParticipantImportMode::Sync->value, $plan->fingerprint));
            self::fail('A blocked full sync must not be applied');
        } catch (ParticipantImportNotApplicable) {
        }

        $this->entityManager->clear();
        self::assertSame($before, $this->eventState());

        // "Update only" of the same file is fine: it only adds
        $update = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);
        $result = $this->dispatch(new ApplyParticipantImport(self::EVENT, $rows, ParticipantImportMode::Update->value, $update->fingerprint));
        self::assertSame(1, $result->added);
        self::assertSame(0, $result->removed);
    }

    public function testUpdateOnlyRestoresAndCountsIt(): void
    {
        $erin = $this->participant('Erin Removed', [self::SOLO]);
        $erin->softDelete(new DateTimeImmutable('2026-09-01'));
        $this->entityManager->flush();

        $rows = new ParticipantImportRows([self::row(2, 'Erin Removed')]);
        $plan = $this->planner->plan(self::EVENT, $rows, ParticipantImportMode::Update);

        $result = $this->dispatch(new ApplyParticipantImport(self::EVENT, $rows, ParticipantImportMode::Update->value, $plan->fingerprint));

        self::assertSame(1, $result->updated);
        self::assertSame(1, $result->restored);
        $this->entityManager->clear();
        self::assertNull($this->participantRow($erin->id->toString())['deleted_at']);
        self::assertSame([self::SOLO], $this->roundsOf($erin->id->toString()));
    }

    private function dispatch(ApplyParticipantImport $message): ParticipantImportResult
    {
        $stamp = $this->messageBus->dispatch($message)->last(HandledStamp::class);
        self::assertInstanceOf(HandledStamp::class, $stamp);
        $result = $stamp->getResult();
        self::assertInstanceOf(ParticipantImportResult::class, $result);

        return $result;
    }

    private function apply(ParticipantImportRows $rows, ParticipantImportMode $mode): ParticipantImportResult
    {
        $plan = $this->planner->plan(self::EVENT, $rows, $mode);
        self::assertTrue($plan->canBeApplied());

        return $this->dispatch(new ApplyParticipantImport(self::EVENT, $rows, $mode->value, $plan->fingerprint));
    }

    private function team(string $name): CompetitionTeam
    {
        $round = $this->entityManager->find(CompetitionRound::class, self::TEAM);
        assert($round instanceof CompetitionRound);

        $team = new CompetitionTeam(Uuid::uuid7(), $round, $name);
        $this->entityManager->persist($team);

        return $team;
    }

    /**
     * @param list<string> $roundIds
     */
    private function participant(string $name, array $roundIds, null|CompetitionTeam $team = null): CompetitionParticipant
    {
        $competition = $this->entityManager->find(Competition::class, self::EVENT);
        assert($competition instanceof Competition);

        $participant = new CompetitionParticipant(Uuid::uuid7(), $name, 'us', $competition);
        $this->entityManager->persist($participant);

        foreach ($roundIds as $roundId) {
            $round = $this->entityManager->find(CompetitionRound::class, $roundId);
            assert($round instanceof CompetitionRound);
            $this->entityManager->persist(new CompetitionParticipantRound(Uuid::uuid7(), $participant, $round, $roundId === self::TEAM ? $team : null));
        }

        return $participant;
    }

    /**
     * @param array<string, string> $teamsByRound
     */
    private static function row(int $number, string $name, null|string $externalId = null, null|string $roundNames = null, array $teamsByRound = []): ParticipantImportRowData
    {
        return new ParticipantImportRowData(
            rowNumber: $number,
            name: $name,
            externalId: $externalId,
            roundNames: $roundNames,
            teamsByRound: $teamsByRound,
        );
    }

    /**
     * @return array{deleted_at: null|string, source: string}
     */
    private function participantRow(string $id): array
    {
        /** @var array{deleted_at: null|string, source: string} $row */
        $row = $this->database->fetchAssociative('SELECT deleted_at, source FROM competition_participant WHERE id = :id', ['id' => $id]);

        return $row;
    }

    /**
     * @return list<string>
     */
    private function roundsOf(string $participantId): array
    {
        /** @var list<string> $rounds */
        $rounds = $this->database->fetchFirstColumn(
            'SELECT round_id FROM competition_participant_round WHERE participant_id = :id ORDER BY round_id',
            ['id' => $participantId],
        );

        return $rounds;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function eventState(): array
    {
        return $this->database->fetchAllAssociative(
            'SELECT cp.id, cp.name, cp.source, cp.deleted_at, cpr.id AS entry_id, cpr.round_id, cpr.team_id
             FROM competition_participant cp
             LEFT JOIN competition_participant_round cpr ON cpr.participant_id = cp.id
             WHERE cp.competition_id = :id ORDER BY cp.id, cpr.id',
            ['id' => self::EVENT],
        );
    }
}
