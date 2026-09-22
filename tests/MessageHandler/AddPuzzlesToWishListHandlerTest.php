<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Message\AddPuzzlesToWishList;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzlesToWishListHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserPuzzleStatuses $statuses;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->statuses = self::getContainer()->get(GetUserPuzzleStatuses::class);
    }

    public function testAddsEveryPuzzleAndRefusesOwnedOnes(): void
    {
        $this->messageBus->dispatch(new AddPuzzlesToWishList(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_UNAPPROVED],
        ));

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertContains(PuzzleFixture::PUZZLE_6000, $statuses->wishlist);
        self::assertContains(PuzzleFixture::PUZZLE_UNAPPROVED, $statuses->wishlist);

        try {
            $this->messageBus->dispatch(new AddPuzzlesToWishList(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                // PUZZLE_300 sits in the player's library (ITEM_20)
                puzzleIds: [PuzzleFixture::PUZZLE_300],
            ));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame('already_in_library', $previous->reason);
        }
    }
}
