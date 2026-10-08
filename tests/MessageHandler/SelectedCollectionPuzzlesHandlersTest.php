<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Exceptions\CollectionNotFound;
use SpeedPuzzling\Web\Message\CopyPuzzlesToCollection;
use SpeedPuzzling\Web\Message\MovePuzzlesToCollection;
use SpeedPuzzling\Web\Message\MovePuzzleToCollection;
use SpeedPuzzling\Web\Message\RemovePuzzlesFromCollection;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\SelectedPuzzlesOutcome;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Move / Copy / Remove of the puzzles selected on a collection page (docs/features/collections/bulk-actions.md), and
 * the single move sharing its rules. Fixtures: COLLECTION_PUBLIC (PLAYER_WITH_STRIPE) holds 500_01, 500_02, 500_04,
 * 500_05, 1000_01, 1000_03, 1000_05 and 300; COLLECTION_STRIPE_TREFL holds 1000_04, 500_02 and 1000_05.
 */
final class SelectedCollectionPuzzlesHandlersTest extends KernelTestCase
{
    private const string UNKNOWN_PUZZLE = '018d0003-0000-0000-0000-0000000fffff';

    private MessageBusInterface $messageBus;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);
    }

    public function testMoveKeepsTheItemsAndOnlyTakesOutWhatTheTargetHolds(): void
    {
        $before = $this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($before);

        $outcome = $this->dispatch(new MovePuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_05, PuzzleFixture::PUZZLE_1000_04, self::UNKNOWN_PUZZLE],
            sourceCollectionId: CollectionFixture::COLLECTION_PUBLIC,
            targetCollectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
        ));

        self::assertSame(1, $outcome->changed);
        self::assertSame(2, $outcome->alreadyThere);
        // 1000_04 was never in the source, the unknown id is nobody's
        self::assertSame(2, $outcome->skipped);

        foreach ([PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_1000_05] as $puzzleId) {
            self::assertNull($this->item(CollectionFixture::COLLECTION_PUBLIC, $puzzleId), $puzzleId);
            self::assertNotNull($this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, $puzzleId), $puzzleId);
        }

        // Moving is not re-adding: same row, same date, same comment
        self::assertSame($before, $this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_01));
        self::assertNotNull($this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));
    }

    public function testMoveIntoAndOutOfTheSystemCollection(): void
    {
        $outcome = $this->dispatch(new MovePuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_04],
            sourceCollectionId: CollectionFixture::COLLECTION_PUBLIC,
            targetCollectionId: null,
        ));

        self::assertSame(1, $outcome->changed);
        self::assertNotNull($this->item(null, PuzzleFixture::PUZZLE_500_04));

        $outcome = $this->dispatch(new MovePuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_04],
            sourceCollectionId: null,
            targetCollectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
        ));

        self::assertSame(1, $outcome->changed);
        self::assertNull($this->item(null, PuzzleFixture::PUZZLE_500_04));
        self::assertNotNull($this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_04));
    }

    public function testCopyKeepsTheSourceAndCarriesDateAndComment(): void
    {
        $source = $this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($source);

        $outcome = $this->dispatch(new CopyPuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02],
            sourceCollectionId: CollectionFixture::COLLECTION_PUBLIC,
            targetCollectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
        ));

        self::assertSame(1, $outcome->changed);
        self::assertSame(1, $outcome->alreadyThere);
        self::assertSame(0, $outcome->skipped);
        self::assertSame($source, $this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_01));

        $copy = $this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($copy);
        self::assertNotSame($source['id'], $copy['id']);
        self::assertSame($source['added_at'], $copy['added_at']);
        self::assertSame($source['comment'], $copy['comment']);
    }

    public function testRemoveTakesThemOutOfThisCollectionOnly(): void
    {
        $outcome = $this->dispatch(new RemovePuzzlesFromCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_1000_04],
            collectionId: CollectionFixture::COLLECTION_PUBLIC,
        ));

        self::assertSame(2, $outcome->changed);
        self::assertSame(1, $outcome->skipped);
        self::assertNull($this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_02));
        self::assertNull($this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04));
        self::assertNotNull($this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_02));
        self::assertNotNull($this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_1000_04));
    }

    public function testAnotherPlayersCollectionIsNotFound(): void
    {
        $this->expectException(CollectionNotFound::class);

        $this->dispatch(new MovePuzzlesToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_500_01],
            sourceCollectionId: CollectionFixture::COLLECTION_PUBLIC,
            // PLAYER_REGULAR's
            targetCollectionId: CollectionFixture::COLLECTION_PRIVATE,
        ));
    }

    public function testRemoveFromAnotherPlayersCollectionIsNotFound(): void
    {
        $this->expectException(CollectionNotFound::class);

        $this->dispatch(new RemovePuzzlesFromCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleIds: [PuzzleFixture::PUZZLE_1500_01],
            collectionId: CollectionFixture::COLLECTION_PRIVATE,
        ));
    }

    public function testSingleMoveKeepsTheItemAndRefusesAForeignCollection(): void
    {
        $before = $this->item(CollectionFixture::COLLECTION_PUBLIC, PuzzleFixture::PUZZLE_500_04);
        self::assertNotNull($before);

        $this->messageBus->dispatch(new MovePuzzleToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: PuzzleFixture::PUZZLE_500_04,
            sourceCollectionId: CollectionFixture::COLLECTION_PUBLIC,
            targetCollectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
            comment: $before['comment'],
        ));

        $after = $this->item(CollectionFixture::COLLECTION_STRIPE_TREFL, PuzzleFixture::PUZZLE_500_04);
        self::assertNotNull($after);
        self::assertSame($before['id'], $after['id']);
        self::assertSame($before['added_at'], $after['added_at']);

        $this->expectException(CollectionNotFound::class);
        $this->messageBus->dispatch(new MovePuzzleToCollection(
            playerId: PlayerFixture::PLAYER_WITH_STRIPE,
            puzzleId: PuzzleFixture::PUZZLE_500_04,
            sourceCollectionId: CollectionFixture::COLLECTION_STRIPE_TREFL,
            targetCollectionId: CollectionFixture::COLLECTION_PRIVATE,
            comment: null,
        ));
    }

    private function dispatch(object $message): SelectedPuzzlesOutcome
    {
        $outcome = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(SelectedPuzzlesOutcome::class, $outcome);

        return $outcome;
    }

    /**
     * @return null|array{id: string, added_at: string, comment: null|string}
     */
    private function item(null|string $collectionId, string $puzzleId): null|array
    {
        /** @var false|array{id: string, added_at: string, comment: null|string} $row */
        $row = $this->connection->fetchAssociative(
            'SELECT id, added_at, comment FROM collection_item
             WHERE player_id = :player AND puzzle_id = :puzzle AND collection_id IS NOT DISTINCT FROM CAST(:collection AS uuid)',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => $puzzleId, 'collection' => $collectionId],
        );

        return $row === false ? null : $row;
    }
}
