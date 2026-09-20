<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\BackfillPuzzlingTeams;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class BackfillPuzzlingTeamsHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        // History as it looked before pairs & teams: group snapshots only, no teams at all
        $this->database->executeStatement('UPDATE puzzle_solving_time SET puzzling_team_id = NULL');
        $this->database->executeStatement('DELETE FROM puzzling_team');
    }

    public function testTimesOfTheSamePeopleLandInOneTeamWhateverTheirOrder(): void
    {
        // TIME_12 lists [REGULAR, PRIVATE]; turn TIME_41 around, as if the other one had tracked it
        $this->setSnapshot(PuzzleSolvingTimeFixture::TIME_41, [
            ['player_id' => PlayerFixture::PLAYER_PRIVATE, 'player_name' => null],
            ['player_id' => PlayerFixture::PLAYER_REGULAR, 'player_name' => null],
        ]);

        $this->runBackfill();

        $teamId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        self::assertNotNull($teamId);
        self::assertSame($teamId, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_41));
        self::assertSame(2, $this->database->fetchOne('SELECT size FROM puzzling_team WHERE id = :id', ['id' => $teamId]));
        self::assertNull($this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $teamId]));
        self::assertSame(0, $this->remaining());
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->runBackfill();
        $teamId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        $teams = $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team');

        $this->runBackfill();

        self::assertSame($teamId, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12));
        self::assertSame($teams, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team'));
    }

    public function testBatchesResumeWhereThePreviousOneStopped(): void
    {
        $before = $this->remaining();
        self::assertGreaterThan(1, $before);

        $envelope = $this->messageBus->dispatch(new BackfillPuzzlingTeams(batchSize: 1));
        $lastId = $envelope->last(HandledStamp::class)?->getResult();

        self::assertIsString($lastId);
        self::assertSame($before - 1, $this->remaining());

        $this->runBackfill();

        self::assertSame(0, $this->remaining());
    }

    public function testSnapshotNamingADeletedAccountBecomesAGuest(): void
    {
        $this->setSnapshot(PuzzleSolvingTimeFixture::TIME_12, [
            ['player_id' => PlayerFixture::PLAYER_REGULAR, 'player_name' => null],
            ['player_id' => '018d0000-0000-0000-0000-00000000dead', 'player_name' => 'Long Gone'],
        ]);

        $this->runBackfill();

        $teamId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        self::assertNotNull($teamId);

        $guests = $this->database->fetchFirstColumn(
            'SELECT guest_name FROM puzzling_team_member WHERE team_id = :id AND player_id IS NULL',
            ['id' => $teamId],
        );
        self::assertSame(['Long Gone'], $guests);
    }

    private function runBackfill(): void
    {
        $afterTimeId = null;

        do {
            $envelope = $this->messageBus->dispatch(new BackfillPuzzlingTeams(batchSize: 2, afterTimeId: $afterTimeId));
            /** @var null|string $afterTimeId */
            $afterTimeId = $envelope->last(HandledStamp::class)?->getResult();
        } while ($afterTimeId !== null);
    }

    /**
     * @param list<array{player_id: null|string, player_name: null|string}> $puzzlers
     */
    private function setSnapshot(string $timeId, array $puzzlers): void
    {
        $this->database->executeStatement(
            'UPDATE puzzle_solving_time SET team = :team WHERE id = :id',
            ['team' => json_encode(['team_id' => null, 'puzzlers' => $puzzlers], JSON_THROW_ON_ERROR), 'id' => $timeId],
        );
    }

    private function teamIdOf(string $timeId): null|string
    {
        /** @var null|string $teamId */
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);

        return $teamId;
    }

    private function remaining(): int
    {
        /** @var int $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE team IS NOT NULL AND puzzling_team_id IS NULL');

        return $count;
    }
}
