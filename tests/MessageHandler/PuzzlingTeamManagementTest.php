<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Events\PuzzlingTeamRenamed;
use SpeedPuzzling\Web\Exceptions\CanNotDeletePuzzlingTeamWithResults;
use SpeedPuzzling\Web\Exceptions\NotAMemberOfPuzzlingTeam;
use SpeedPuzzling\Web\Message\DeletePuzzlingTeam;
use SpeedPuzzling\Web\Message\PreparePuzzlingTeam;
use SpeedPuzzling\Web\Message\RenamePuzzlingTeam;
use SpeedPuzzling\Web\MessageHandler\NotifyWhenPuzzlingTeamRenamed;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Naming, preparing and deleting pairs/teams - docs/features/pairs-and-teams/README.md, D3 D4 D6.
 */
final class PuzzlingTeamManagementTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;
    private string $fixturePairId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);

        // PLAYER_REGULAR + PLAYER_PRIVATE, two times together
        $pairId = $this->database->fetchOne('SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id', ['id' => PuzzleSolvingTimeFixture::TIME_12]);
        self::assertIsString($pairId);
        $this->fixturePairId = $pairId;
    }

    public function testAnyRegisteredMemberNamesAndRenamesTheTeam(): void
    {
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, '  The   Speedsters '));

        self::assertSame(
            ['name' => 'The Speedsters', 'named_by_id' => PlayerFixture::PLAYER_REGULAR],
            $this->database->fetchAssociative('SELECT name, named_by_id FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]),
        );

        // The other member has the same right, not only whoever named it first
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_PRIVATE, 'Slowpokes'));

        self::assertSame(
            ['name' => 'Slowpokes', 'named_by_id' => PlayerFixture::PLAYER_PRIVATE],
            $this->database->fetchAssociative('SELECT name, named_by_id FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]),
        );
    }

    public function testEmptyNameTakesTheNameAway(): void
    {
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, 'Speedsters'));
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, '   '));

        self::assertNull($this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));
    }

    public function testNameIsCutAtFiftyCharacters(): void
    {
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, str_repeat('ž', 80)));

        self::assertSame(str_repeat('ž', 50), $this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));
    }

    public function testSomebodyOutsideTheTeamCannotRenameIt(): void
    {
        try {
            $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_ADMIN, 'Mine now'));
            self::fail('Renaming somebody else\'s pair must be refused');
        } catch (NotAMemberOfPuzzlingTeam) {
            // An HTTP exception (403): UnwrapHttpExceptionMiddleware hands it over as it is
        }

        self::assertNull($this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));
    }

    public function testOtherMembersAreToldWhoRenamedTheTeam(): void
    {
        // The event is routed async, so the handler is called the way the consumer would
        $event = new PuzzlingTeamRenamed(Uuid::fromString($this->fixturePairId), Uuid::fromString(PlayerFixture::PLAYER_REGULAR));
        $this->notify($event);
        // Thinking twice about the name is still one piece of news
        $this->notify($event);

        $notifications = $this->database->fetchAllAssociative(
            "SELECT player_id, actor_player_id FROM notification WHERE type = 'PuzzlingTeamRenamed' AND target_puzzling_team_id = :id",
            ['id' => $this->fixturePairId],
        );

        self::assertSame([['player_id' => PlayerFixture::PLAYER_PRIVATE, 'actor_player_id' => PlayerFixture::PLAYER_REGULAR]], $notifications);
    }

    public function testRenamingRecordsTheEventTheNotificationComesFrom(): void
    {
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, 'Speedsters'));

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $events = array_filter(
            array_map(static fn($envelope): object => $envelope->getMessage(), $transport->getSent()),
            static fn(object $message): bool => $message instanceof PuzzlingTeamRenamed,
        );

        self::assertCount(1, $events);

        // Saving the same name again is no news
        $transport->reset();
        $this->messageBus->dispatch(new RenamePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR, 'Speedsters'));
        self::assertCount(0, $transport->getSent());
    }

    public function testMemberWhoBlocksTheRenamingPlayerHearsNothing(): void
    {
        // Fixtures: PLAYER_REGULAR has blocked PLAYER_PRIVATE
        $this->notify(new PuzzlingTeamRenamed(Uuid::fromString($this->fixturePairId), Uuid::fromString(PlayerFixture::PLAYER_PRIVATE)));

        self::assertSame(0, $this->database->fetchOne("SELECT COUNT(*) FROM notification WHERE type = 'PuzzlingTeamRenamed'"));
    }

    public function testTeamWithResultsCannotBeDeleted(): void
    {
        try {
            $this->messageBus->dispatch(new DeletePuzzlingTeam($this->fixturePairId, PlayerFixture::PLAYER_REGULAR));
            self::fail('A pair with results must not be deletable');
        } catch (CanNotDeletePuzzlingTeamWithResults) {
        }

        self::assertSame(2, $this->database->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time WHERE puzzling_team_id = :id', ['id' => $this->fixturePairId]));
    }

    public function testPreparedTeamCanBeDeletedByAMemberOnly(): void
    {
        $this->messageBus->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_WITH_STRIPE, ['#admin', 'Grandma'], 'Knitting circle'));

        /** @var array{id: string, size: int, prepared_by_id: string}|false $team */
        $team = $this->database->fetchAssociative("SELECT id, size, prepared_by_id FROM puzzling_team WHERE name = 'Knitting circle'");
        self::assertNotFalse($team);
        self::assertSame(3, $team['size']);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $team['prepared_by_id']);

        try {
            $this->messageBus->dispatch(new DeletePuzzlingTeam($team['id'], PlayerFixture::PLAYER_REGULAR));
            self::fail('Somebody outside the team must not delete it');
        } catch (NotAMemberOfPuzzlingTeam) {
        }

        // The other registered member may - the team is as much theirs
        $this->messageBus->dispatch(new DeletePuzzlingTeam($team['id'], PlayerFixture::PLAYER_ADMIN));

        self::assertFalse($this->database->fetchOne('SELECT 1 FROM puzzling_team WHERE id = :id', ['id' => $team['id']]));
        self::assertSame(0, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team_member WHERE team_id = :id', ['id' => $team['id']]));
    }

    public function testPreparingAnExistingTeamFindsItInsteadOfDuplicatingIt(): void
    {
        $teamsBefore = $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team');

        $this->messageBus->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_PRIVATE, ['#player1'], 'Speedsters'));

        self::assertSame($teamsBefore, $this->database->fetchOne('SELECT COUNT(*) FROM puzzling_team'));
        self::assertSame('Speedsters', $this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));

        // …and it names, never renames
        $this->messageBus->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_PRIVATE, ['#player1'], 'Something else'));
        self::assertSame('Speedsters', $this->database->fetchOne('SELECT name FROM puzzling_team WHERE id = :id', ['id' => $this->fixturePairId]));
    }

    private function notify(PuzzlingTeamRenamed $event): void
    {
        /** @var NotifyWhenPuzzlingTeamRenamed $handler */
        $handler = self::getContainer()->get(NotifyWhenPuzzlingTeamRenamed::class);
        $handler($event);

        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->flush();
    }

    public function testPreparingWithNobodyIsRefused(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->messageBus->dispatch(new PreparePuzzlingTeam(PlayerFixture::PLAYER_WITH_STRIPE, ['#player4'], null));
    }
}
