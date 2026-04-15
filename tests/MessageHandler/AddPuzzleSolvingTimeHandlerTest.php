<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testAddSoloTimePersistsRowWithRequestedAttributes(): void
    {
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '01:00:00',
            comment: 'Relaxed Sunday session',
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: true,
            unboxed: false,
        ));

        /** @var array{seconds_to_solve: int, comment: null|string, first_attempt: bool, unboxed: bool, player_id: string, puzzle_id: string, team: null|string}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, comment, first_attempt, unboxed, player_id, puzzle_id, team FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNotFalse($row);
        self::assertSame(3600, $row['seconds_to_solve']);
        self::assertSame('Relaxed Sunday session', $row['comment']);
        self::assertTrue((bool) $row['first_attempt']);
        self::assertFalse((bool) $row['unboxed']);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $row['player_id']);
        self::assertSame(PuzzleFixture::PUZZLE_1500_01, $row['puzzle_id']);
        self::assertNull($row['team']);
    }

    public function testAddSuspiciouslyFastTimeIsRejected(): void
    {
        // 500 pieces solved in 1 minute = 500 PPM, well above the 100 PPM threshold.
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new AddPuzzleSolvingTime(
                timeId: Uuid::uuid7(),
                userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
                puzzleId: PuzzleFixture::PUZZLE_500_01,
                competitionId: null,
                time: '00:01:00',
                comment: null,
                finishedPuzzlesPhoto: null,
                groupPlayers: [],
                finishedAt: null,
                firstAttempt: false,
                unboxed: false,
            ));
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousPpm::class, $exception->getPrevious());
            throw $exception;
        }
    }

    public function testUnknownCompetitionIdIsSilentlyDroppedWithoutFailing(): void
    {
        // Controller passes user-selectable competitionId; a stale value shouldn't crash the whole save.
        $timeId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: $timeId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: '00000000-0000-0000-0000-000000000000',
            time: '01:05:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));

        /** @var null|string|false $competitionId */
        $competitionId = $this->database->fetchOne(
            'SELECT competition_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => $timeId->toString()],
        );

        self::assertNull($competitionId);
    }
}
