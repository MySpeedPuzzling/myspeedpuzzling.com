<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\AddPuzzleTracking;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Every pair/team time belongs to the puzzling_team made of exactly its people
 * (docs/features/pairs-and-teams/README.md).
 */
final class PuzzlingTeamAssignmentTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testSoloTimeHasNoTeam(): void
    {
        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, []);

        self::assertNull($this->teamIdOf($timeId->toString()));
    }

    public function testPairTimeJoinsTheExistingPairWhoeverTracksIt(): void
    {
        // The fixtures already hold the pair PLAYER_REGULAR + PLAYER_PRIVATE (TIME_12, TIME_41)
        $existingTeamId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        self::assertNotNull($existingTeamId);
        self::assertSame($existingTeamId, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_41));

        $teamsBefore = $this->teamsCount();

        $trackedByRegular = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, ['#player2']);
        $trackedByPrivate = $this->addTime(PlayerFixture::PLAYER_PRIVATE_USER_ID, ['#PLAYER1']);

        self::assertSame($existingTeamId, $this->teamIdOf($trackedByRegular->toString()));
        self::assertSame($existingTeamId, $this->teamIdOf($trackedByPrivate->toString()));
        self::assertSame($teamsBefore, $this->teamsCount());
    }

    public function testNewPeopleMakeANewTeamWithItsMembers(): void
    {
        $timeId = $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, ['#player2', 'Babička Marie']);

        $teamId = $this->teamIdOf($timeId->toString());
        self::assertNotNull($teamId);
        self::assertNotSame($this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12), $teamId);

        /** @var array{size: int, name: null|string}|false $team */
        $team = $this->database->fetchAssociative('SELECT size, name FROM puzzling_team WHERE id = :id', ['id' => $teamId]);
        self::assertNotFalse($team);
        self::assertSame(3, $team['size']);
        self::assertNull($team['name']);

        $members = $this->database->fetchAllAssociative(
            'SELECT player_id, guest_name, position FROM puzzling_team_member WHERE team_id = :id ORDER BY position',
            ['id' => $teamId],
        );

        self::assertSame([
            ['player_id' => PlayerFixture::PLAYER_REGULAR, 'guest_name' => null, 'position' => 0],
            ['player_id' => PlayerFixture::PLAYER_PRIVATE, 'guest_name' => null, 'position' => 1],
            ['player_id' => null, 'guest_name' => 'Babička Marie', 'position' => 2],
        ], $members);

        // The same people again, entered differently: same team, nothing new
        $teamsBefore = $this->teamsCount();
        $again = $this->addTime(PlayerFixture::PLAYER_PRIVATE_USER_ID, ['babicka marie', '#player1']);

        self::assertSame($teamId, $this->teamIdOf($again->toString()));
        self::assertSame($teamsBefore, $this->teamsCount());
    }

    public function testRelaxTrackingGetsItsTeamToo(): void
    {
        $trackingId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleTracking(
            trackingId: $trackingId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: ['#player2'],
            finishedAt: null,
        ));

        self::assertSame($this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12), $this->teamIdOf($trackingId->toString()));
    }

    public function testRejectedTimeCreatesNoTeam(): void
    {
        $teamsBefore = $this->teamsCount();

        try {
            // 500 pieces in a minute: refused as suspicious
            $this->addTime(PlayerFixture::PLAYER_REGULAR_USER_ID, ['A brand new guest'], time: '00:01:00', puzzleId: PuzzleFixture::PUZZLE_500_01);
            self::fail('The time should have been refused');
        } catch (HandlerFailedException) {
        }

        self::assertSame($teamsBefore, $this->teamsCount());
    }

    public function testEditingTheGroupMovesTheTimeToAnotherTeam(): void
    {
        $pairId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);

        $this->editTime(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_REGULAR_USER_ID, ['#player2', 'Eva']);

        $trioId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);
        self::assertNotNull($trioId);
        self::assertNotSame($pairId, $trioId);
        // The pair itself is untouched and keeps its other time
        self::assertSame($pairId, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_41));
    }

    public function testEditByAMemberKeepsTheTeamAroundTheTracker(): void
    {
        $pairId = $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12);

        // PLAYER_PRIVATE edits PLAYER_REGULAR's time, listing everyone but the tracker
        $this->editTime(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_PRIVATE_USER_ID, ['#player2']);

        self::assertSame($pairId, $this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12));
    }

    public function testEditingToSoloClearsTheTeam(): void
    {
        $this->editTime(PuzzleSolvingTimeFixture::TIME_12, PlayerFixture::PLAYER_REGULAR_USER_ID, []);

        self::assertNull($this->teamIdOf(PuzzleSolvingTimeFixture::TIME_12));
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function addTime(string $userId, array $groupPlayers, string $time = '03:00:00', string $puzzleId = PuzzleFixture::PUZZLE_1500_01): UuidInterface
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: $userId,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        return $timeId;
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function editTime(string $timeId, string $userId, array $groupPlayers): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: $userId,
            puzzleSolvingTimeId: $timeId,
            competitionId: null,
            time: '01:00:00',
            comment: null,
            groupPlayers: $groupPlayers,
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function teamIdOf(string $timeId): null|string
    {
        /** @var null|string|false $teamId */
        $teamId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => $timeId]);
        self::assertNotFalse($teamId, 'The time does not exist');

        return $teamId;
    }

    private function teamsCount(): int
    {
        /** @var int $count */
        $count = $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team');

        return $count;
    }
}
