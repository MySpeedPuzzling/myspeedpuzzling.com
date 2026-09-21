<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\NotAMemberOfPuzzlingTeam;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\ArchivePuzzlingTeam;
use SpeedPuzzling\Web\Message\CleanupEmptyPuzzlingTeams;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\PreparePuzzlingTeam;
use SpeedPuzzling\Web\Query\GetCoPuzzlers;
use SpeedPuzzling\Web\Results\TeamSuggestion;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

final class PuzzlingTeamArchiveTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $fixturePairId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        // PLAYER_REGULAR + PLAYER_PRIVATE, two results
        $pairId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($pairId);
        $this->fixturePairId = $pairId;
    }

    public function testArchiveIsOneMembersOwnAndTouchesNothingElse(): void
    {
        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_PRIVATE));
        // Twice is once
        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_PRIVATE));

        self::assertTrue($this->pairOf(PlayerFixture::PLAYER_PRIVATE)->archived);
        self::assertFalse($this->pairOf(PlayerFixture::PLAYER_REGULAR)->archived, 'The other member is not affected');
        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE puzzling_team_id = :id', ['id' => $this->fixturePairId]));

        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_PRIVATE, archive: false));
        self::assertFalse($this->pairOf(PlayerFixture::PLAYER_PRIVATE)->archived);
    }

    public function testSomebodyOutsideTheTeamCannotArchiveIt(): void
    {
        $this->expectException(NotAMemberOfPuzzlingTeam::class);

        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_ADMIN));
    }

    public function testPuzzlingWithTheSamePeopleAgainBringsItBack(): void
    {
        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_PRIVATE));
        $this->messageBus->dispatch(new ArchivePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR));

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_PRIVATE_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: ['#player1'],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        // Back for whoever used it - the other member archived it for themselves and keeps it that way
        self::assertFalse($this->pairOf(PlayerFixture::PLAYER_PRIVATE)->archived);
        self::assertTrue($this->pairOf(PlayerFixture::PLAYER_REGULAR)->archived);
    }

    public function testCleanupRemovesOnlyTeamsLeftWithNothing(): void
    {
        // An edit moves TIME_41 to a trio; then back - the trio is left empty
        $this->editGroup(PuzzleSolvingTimeFixture::TIME_41, ['#player2', 'Eva']);
        $trioId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_41]);
        self::assertIsString($trioId);
        $this->editGroup(PuzzleSolvingTimeFixture::TIME_41, ['#player2']);

        // Prepared on purpose, and a named leftover: both stay
        $this->messageBus->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_WITH_STRIPE, ['#admin'], null));
        $this->editGroup(PuzzleSolvingTimeFixture::TIME_12, ['#player2', 'Named One']);
        $namedId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($namedId);
        $this->database->executeStatement("UPDATE puzzling_team SET name = 'Keep me' WHERE id = :id", ['id' => $namedId]);
        $this->editGroup(PuzzleSolvingTimeFixture::TIME_12, ['#player2']);

        // Fresh leftovers are left alone: somebody may be creating them right now
        self::assertSame(0, $this->cleanup());

        $this->database->executeStatement('UPDATE puzzling_team SET created_at = :at', ['at' => self::getContainer()->get(ClockInterface::class)->now()->modify('-2 days')->format('Y-m-d H:i:s')]);

        self::assertSame(1, $this->cleanup());
        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $trioId]));
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $namedId]));
        self::assertNotFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));
        self::assertSame(1, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team WHERE prepared_by_id IS NOT NULL'));
        self::assertSame(0, $this->cleanup());
    }

    private function cleanup(): int
    {
        /** @var int $removed */
        $removed = $this->messageBus->dispatch(new CleanupEmptyPuzzlingTeams())->last(HandledStamp::class)?->getResult();

        return $removed;
    }

    /**
     * @param array<string> $groupPlayers
     */
    private function editGroup(string $timeId, array $groupPlayers): void
    {
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
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

    private function pairOf(string $playerId): TeamSuggestion
    {
        foreach (self::getContainer()->get(GetCoPuzzlers::class)->forPlayer($playerId)->teams as $team) {
            if ($team->teamId === $this->fixturePairId) {
                return $team;
            }
        }

        self::fail('The fixture pair is not among the suggestions');
    }
}
