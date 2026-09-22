<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Message\BorrowPuzzlesFromPlayer;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class BorrowPuzzlesFromPlayerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserPuzzleStatuses $statuses;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->statuses = self::getContainer()->get(GetUserPuzzleStatuses::class);
    }

    public function testRecordsEveryPuzzleAsBorrowedFromARegisteredPlayer(): void
    {
        $this->messageBus->dispatch(new BorrowPuzzlesFromPlayer(
            borrowerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_9000],
            ownerPlayerId: PlayerFixture::PLAYER_WITH_FAVORITES,
        ));

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertArrayHasKey(PuzzleFixture::PUZZLE_6000, $statuses->borrowedPuzzleIds);
        self::assertArrayHasKey(PuzzleFixture::PUZZLE_9000, $statuses->borrowedPuzzleIds);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $statuses->borrowedFromNames[PuzzleFixture::PUZZLE_6000]);
    }

    public function testAlreadyBorrowedPuzzleRejectsTheBatch(): void
    {
        try {
            $this->messageBus->dispatch(new BorrowPuzzlesFromPlayer(
                borrowerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
                // PUZZLE_1500_02 is already borrowed from PLAYER_REGULAR (LENT_05)
                puzzleIds: [PuzzleFixture::PUZZLE_1500_02],
                ownerName: 'Somebody',
            ));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame('already_borrowed', $previous->reason);
        }
    }
}
