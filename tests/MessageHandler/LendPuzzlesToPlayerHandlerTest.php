<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CannotLendToSelf;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Message\LendPuzzlesToPlayer;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class LendPuzzlesToPlayerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserPuzzleStatuses $statuses;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->statuses = self::getContainer()->get(GetUserPuzzleStatuses::class);
    }

    public function testLendsEveryPuzzleToTheSamePerson(): void
    {
        $this->messageBus->dispatch(new LendPuzzlesToPlayer(
            ownerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_300, PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_300],
            borrowerPlayerId: null,
            borrowerName: 'Anna',
            notes: 'Pile of two',
        ));

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame('Anna', $statuses->lentToNames[PuzzleFixture::PUZZLE_300] ?? null);
        self::assertSame('Anna', $statuses->lentToNames[PuzzleFixture::PUZZLE_6000] ?? null);
    }

    public function testBatchWithAnAlreadyLentPuzzleIsRefusedAsAWhole(): void
    {
        try {
            $this->messageBus->dispatch(new LendPuzzlesToPlayer(
                ownerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE,
                // PUZZLE_2000 is already lent to PLAYER_REGULAR (LENT_01)
                puzzleIds: [PuzzleFixture::PUZZLE_1000_04, PuzzleFixture::PUZZLE_2000],
                borrowerName: 'Anna',
            ));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame(PuzzleFixture::PUZZLE_2000, $previous->puzzleId);
            self::assertSame('already_lent', $previous->reason);
        }

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_1000_04, $statuses->lentToNames, 'nothing written when one puzzle is not eligible');
    }

    public function testEmptyBatchAndSelfLendAreRefused(): void
    {
        try {
            $this->messageBus->dispatch(new LendPuzzlesToPlayer(PlayerFixture::PLAYER_WITH_STRIPE, [], borrowerName: 'Anna'));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(MultiscanBatchRejected::class, $e->getPrevious());
        }

        try {
            $this->messageBus->dispatch(new LendPuzzlesToPlayer(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_6000], borrowerPlayerId: PlayerFixture::PLAYER_WITH_STRIPE));
            self::fail('Expected CannotLendToSelf');
        } catch (HandlerFailedException $e) {
            self::assertInstanceOf(CannotLendToSelf::class, $e->getPrevious());
        }

        try {
            $this->messageBus->dispatch(new LendPuzzlesToPlayer(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_6000]));
            self::fail('Expected MultiscanBatchRejected (missing person)');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame('missing_person', $previous->reason);
        }
    }
}
