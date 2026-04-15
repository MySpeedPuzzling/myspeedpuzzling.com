<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CanNotModifyOtherPlayersTime;
use SpeedPuzzling\Web\Message\DeletePuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeletePuzzleSolvingTimeHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private Connection $database;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOwnerCanDeleteTheirTime(): void
    {
        $this->messageBus->dispatch(new DeletePuzzleSolvingTime(
            currentUserId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
        ));

        /** @var int|string|false $count */
        $count = $this->database->fetchOne(
            'SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_01],
        );

        self::assertSame(0, (int) $count);
    }

    public function testNonOwnerCannotDeleteSomeoneElsesTime(): void
    {
        // TIME_01 belongs to PLAYER_REGULAR; PLAYER_WITH_FAVORITES must be blocked.
        $this->expectException(HandlerFailedException::class);

        try {
            $this->messageBus->dispatch(new DeletePuzzleSolvingTime(
                currentUserId: PlayerFixture::PLAYER_WITH_FAVORITES_USER_ID,
                puzzleSolvingTimeId: PuzzleSolvingTimeFixture::TIME_01,
            ));
        } catch (HandlerFailedException $exception) {
            self::assertInstanceOf(CanNotModifyOtherPlayersTime::class, $exception->getPrevious());

            /** @var int|string|false $count */
            $count = $this->database->fetchOne(
                'SELECT COUNT(*) FROM puzzle_solving_time WHERE id = :id',
                ['id' => PuzzleSolvingTimeFixture::TIME_01],
            );

            // The time must still exist — transaction must have rolled back.
            self::assertSame(1, (int) $count);

            throw $exception;
        }
    }
}
