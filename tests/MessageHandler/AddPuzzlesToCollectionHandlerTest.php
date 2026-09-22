<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Exceptions\MultiscanBatchRejected;
use SpeedPuzzling\Web\Message\AddPuzzlesToCollection;
use SpeedPuzzling\Web\Query\GetUserPuzzleStatuses;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;

final class AddPuzzlesToCollectionHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private GetUserPuzzleStatuses $statuses;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->statuses = self::getContainer()->get(GetUserPuzzleStatuses::class);
    }

    public function testAddsToTheSystemCollectionAndToANamedOne(): void
    {
        $this->messageBus->dispatch(new AddPuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_6000, PuzzleFixture::PUZZLE_9000],
            collectionId: null,
        ));

        $this->messageBus->dispatch(new AddPuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_6000],
            collectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
        ));

        $this->statuses->reset();
        $statuses = $this->statuses->byPlayerId(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertContains(PuzzleFixture::PUZZLE_6000, $statuses->collection);
        self::assertContains(PuzzleFixture::PUZZLE_9000, $statuses->collection);
        self::assertArrayHasKey(CollectionFixture::COLLECTION_STRIPE_TREFL, $statuses->puzzleCollections[PuzzleFixture::PUZZLE_6000]);
    }

    public function testAlreadyInTheCollectionRejectsTheBatchAndForeignCollectionIsRefused(): void
    {
        try {
            $this->messageBus->dispatch(new AddPuzzlesToCollection(
                playerId: PlayerFixture::PLAYER_WITH_STRIPE,
                // PUZZLE_1000_04 is already in COLLECTION_STRIPE_TREFL (ITEM_17)
                puzzleIds: [PuzzleFixture::PUZZLE_1000_04],
                collectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
            ));
            self::fail('Expected MultiscanBatchRejected');
        } catch (HandlerFailedException $e) {
            $previous = $e->getPrevious();
            self::assertInstanceOf(MultiscanBatchRejected::class, $previous);
            self::assertSame('already_in_collection', $previous->reason);
        }

        // HTTP exceptions are unwrapped by UnwrapHttpExceptionMiddleware
        $this->expectException(CollectionNotFound::class);
        $this->messageBus->dispatch(new AddPuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_9000],
            // belongs to PLAYER_REGULAR
            collectionId: CollectionFixture::COLLECTION_PRIVATE,
        ));
    }
}
