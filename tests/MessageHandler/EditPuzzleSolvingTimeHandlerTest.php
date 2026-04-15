<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Exceptions\SuspiciousPpm;
use SpeedPuzzling\Web\Message\EditPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class EditPuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOwnerCanChangeTimeAndMetadata(): void
    {
        // TIME_01 is owned by PLAYER_REGULAR on PUZZLE_500_01, 1800 seconds, no comment.
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
            competitionId: null,
            time: '00:35:00',
            comment: 'Edited comment',
            groupPlayers: [],
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: true,
        ));

        /** @var array{seconds_to_solve: int, comment: null|string, first_attempt: bool, unboxed: bool}|false $row */
        $row = $this->database->fetchAssociative(
            'SELECT seconds_to_solve, comment, first_attempt, unboxed FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNotFalse($row);
        self::assertSame(2100, $row['seconds_to_solve']);
        self::assertSame('Edited comment', $row['comment']);
        self::assertFalse((bool) $row['first_attempt']);
        self::assertTrue((bool) $row['unboxed']);
    }

    public function testRelaxModeClearsTheTime(): void
    {
        // Relax mode dispatches with time: null; handler must leave seconds_to_solve NULL so the
        // record shows as a relax solve instead of a speed-puzzling one.
        $this->messageBus->dispatch(new EditPuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
            competitionId: null,
            time: null,
            comment: null,
            groupPlayers: [],
            finishedAt: null,
            finishedPuzzlesPhoto: null,
            firstAttempt: false,
            unboxed: false,
        ));

        /** @var null|int|false $seconds */
        $seconds = $this->database->fetchOne(
            'SELECT seconds_to_solve FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertNull($seconds);
    }

    public function testNonOwnerIsRejected(): void
    {
        // TIME_01 is PLAYER_REGULAR's; PLAYER_WITH_FAVORITES must not be able to modify it.
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new EditPuzzleSolvingTime(
                currentUserId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID,
                puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
                competitionId: null,
                time: '00:10:00',
                comment: 'hijack attempt',
                groupPlayers: [],
                finishedAt: null,
                finishedPuzzlesPhoto: null,
                firstAttempt: false,
                unboxed: false,
            ));
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(CanNotModifyOtherPlayersTime::class, $exception->getPrevious());
            throw $exception;
        }
    }

    public function testSuspiciouslyFastEditIsRejected(): void
    {
        // TIME_01 is on PUZZLE_500_01 (500 pieces). 1 minute = 500 PPM — well above the 100 PPM cap.
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new EditPuzzleSolvingTime(
                currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
                puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
                competitionId: null,
                time: '00:01:00',
                comment: null,
                groupPlayers: [],
                finishedAt: null,
                finishedPuzzlesPhoto: null,
                firstAttempt: false,
                unboxed: false,
            ));
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(SuspiciousPpm::class, $exception->getPrevious());
            throw $exception;
        }
    }
}
